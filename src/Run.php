<?php

namespace IdempotentImport;

use IdempotentImport\Importer\Comments as CommentsImporter;
use IdempotentImport\Importer\Options as OptionsImporter;
use IdempotentImport\Importer\Posts as PostsImporter;
use IdempotentImport\Importer\Terms as TermsImporter;
use IdempotentImport\Importer\Users as UsersImporter;

/**
 * Top-level orchestrator. Validates the snapshot, wires shared services, then
 * runs every importer's create phase followed by every importer's rewrite phase
 * (two passes so references are never forward-looking), writes the report, and
 * sets the exit code.
 */
class Run {

	/**
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function execute( array $args, array $assoc_args ) {
		$dryRun = ! empty( $assoc_args['dry-run'] );
		$quiet  = ! empty( $assoc_args['quiet'] );

		$snapshotDir = isset( $args[0] ) ? (string) $args[0] : '';
		if ( '' === $snapshotDir ) {
			\WP_CLI::error( 'snapshot-dir is required.' );
		}

		$snapshot = new Snapshot( $snapshotDir );
		try {
			$snapshot->assertReadable();
			$manifest = $snapshot->manifest();
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}

		if ( ! $manifest->isCompatible() && empty( $assoc_args['force'] ) ) {
			\WP_CLI::error(
				sprintf(
					'Snapshot schema_version %s is not supported by this importer (expects %d.x). Re-run with --force to try anyway.',
					$manifest->schemaVersion(),
					Manifest::SUPPORTED_MAJOR
				)
			);
		}

		$blogId = $this->resolveBlogId( $assoc_args );

		$sourceKey = ! empty( $assoc_args['source-key'] ) ? (string) $assoc_args['source-key'] : $manifest->sourceKey();

		$config = isset( $assoc_args['config'] )
			? Config::fromFile( (string) $assoc_args['config'] )
			: new Config();
		$config->applyCliOverrides( $assoc_args );

		$ledger = $this->buildLedger( $sourceKey );
		$idMap  = new IdMap( $ledger );

		$logger = new Logger( $dryRun ? null : ( rtrim( $snapshotDir, '/\\' ) . '/report.log' ) );
		$logger->open();

		$report  = new Report( $sourceKey, $manifest->source(), $this->provenance( $assoc_args ) );
		$decoder = new Decoder();
		$wp      = new Wp();

		$ctx             = new Context( $wp, $idMap, $config, $logger, $decoder, $manifest, $report );
		$ctx->phase      = 'create';
		$ctx->onConflict = isset( $assoc_args['on-conflict'] ) ? (string) $assoc_args['on-conflict'] : 'update';
		$ctx->dryRun     = $dryRun;

		$registry = Bootstrap::defaultRegistry();
		Bootstrap::applyProjectExtensions( $registry, $ctx );

		$importers = $this->buildImporters( $snapshot, $ctx, $registry, $assoc_args );

		$this->announceStart( $snapshotDir, $sourceKey, $dryRun, $blogId );

		if ( function_exists( 'wp_suspend_cache_addition' ) ) {
			wp_suspend_cache_addition( true );
		}
		$started = microtime( true );

		// Phase 1: create everything, populate the IdMap.
		foreach ( $importers as $importer ) {
			$importer->createPhase();
		}

		// Phase 2: rewrite references now the IdMap is complete.
		$ctx->phase = 'rewrite';
		foreach ( $importers as $importer ) {
			$importer->rewritePhase();
		}

		if ( ! $dryRun ) {
			$this->writeReport( $snapshotDir, $report, $logger );
		}

		$elapsed = microtime( true ) - $started;
		$this->announceFinish( $report, $logger, $elapsed, $dryRun );

		$logger->close();

		if ( is_multisite() && null !== $blogId ) {
			restore_current_blog();
		}

		if ( $dryRun ) {
			return;
		}
		if ( $logger->skipCount() > 0 ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * @param Snapshot $snapshot
	 * @param Context  $ctx
	 * @param Registry $registry
	 * @param array    $assoc_args
	 * @return \IdempotentImport\Contracts\EntityImporter[]
	 */
	private function buildImporters( Snapshot $snapshot, Context $ctx, Registry $registry, array $assoc_args ) {
		$all = array(
			'users'    => new UsersImporter( $snapshot, $ctx, $registry ),
			'terms'    => new TermsImporter( $snapshot, $ctx, $registry ),
			'posts'    => new PostsImporter( $snapshot, $ctx, $registry ),
			'comments' => new CommentsImporter( $snapshot, $ctx, $registry ),
			'options'  => new OptionsImporter( $snapshot, $ctx, $registry ),
		);

		$only = isset( $assoc_args['only'] ) ? array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['only'] ) ) ) : array();
		$skip = isset( $assoc_args['skip'] ) ? array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['skip'] ) ) ) : array();

		$selected = array();
		foreach ( $all as $key => $importer ) {
			if ( $only && ! in_array( $key, $only, true ) ) {
				continue;
			}
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}
			$selected[] = $importer;
		}
		return $selected;
	}

	/**
	 * @param string $sourceKey
	 * @return Contracts\Ledger
	 */
	private function buildLedger( $sourceKey ) {
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) ) {
			$ledger = new DbLedger( $wpdb, $sourceKey );
			$ledger->ensureTable();
			return $ledger;
		}
		return new ArrayLedger();
	}

	/**
	 * @param array $assoc_args
	 * @return int|null
	 */
	private function resolveBlogId( array $assoc_args ) {
		if ( ! is_multisite() ) {
			return null;
		}
		if ( empty( $assoc_args['blog-id'] ) ) {
			\WP_CLI::error( 'Multisite detected: --blog-id=<id> is required.' );
		}
		$id = (int) $assoc_args['blog-id'];
		if ( $id < 1 ) {
			\WP_CLI::error( 'Invalid --blog-id.' );
		}
		switch_to_blog( $id );
		return $id;
	}

	/**
	 * @param array $assoc_args
	 * @return array
	 */
	private function provenance( array $assoc_args ) {
		$keys = array( 'on-conflict', 'attachments', 'options', 'default-author', 'only', 'skip', 'config' );
		$out  = array();
		foreach ( $keys as $k ) {
			if ( isset( $assoc_args[ $k ] ) ) {
				$out[ $k ] = $assoc_args[ $k ];
			}
		}
		return $out;
	}

	/**
	 * @param string $snapshotDir
	 * @param Report $report
	 * @param Logger $logger
	 */
	private function writeReport( $snapshotDir, Report $report, Logger $logger ) {
		$path = rtrim( $snapshotDir, '/\\' ) . '/import-report.json';
		try {
			$json = Json::encode( $report->build( $logger->skips() ) );
			file_put_contents( $path, $json );
		} catch ( \Throwable $e ) {
			\WP_CLI::warning( 'Could not write import-report.json: ' . $e->getMessage() );
		}
	}

	/**
	 * @param string   $snapshotDir
	 * @param string   $sourceKey
	 * @param bool     $dryRun
	 * @param int|null $blogId
	 */
	private function announceStart( $snapshotDir, $sourceKey, $dryRun, $blogId ) {
		$bits = array( "source={$sourceKey}" );
		if ( $dryRun ) {
			$bits[] = 'dry-run';
		}
		if ( null !== $blogId ) {
			$bits[] = "blog={$blogId}";
		}
		\WP_CLI::log( "idempotent-import <- {$snapshotDir} (" . implode( ', ', $bits ) . ')' );
	}

	/**
	 * @param Report $report
	 * @param Logger $logger
	 * @param float  $elapsed
	 * @param bool   $dryRun
	 */
	private function announceFinish( Report $report, Logger $logger, $elapsed, $dryRun ) {
		$lines = array();
		foreach ( $report->outcomes() as $type => $counts ) {
			$parts = array();
			foreach ( $counts as $outcome => $n ) {
				if ( $n > 0 ) {
					$parts[] = "{$n} {$outcome}";
				}
			}
			$lines[] = sprintf( '  %-11s %s', $type, $parts ? implode( ', ', $parts ) : '0' );
		}
		\WP_CLI::log( ( $dryRun ? 'Dry-run summary:' : 'Import summary:' ) );
		foreach ( $lines as $line ) {
			\WP_CLI::log( $line );
		}
		\WP_CLI::log( sprintf( 'Skipped: %d. Warnings: %d. Elapsed: %.2fs.', $logger->skipCount(), $logger->warnCount(), $elapsed ) );

		if ( ! $dryRun && 0 === $logger->skipCount() ) {
			\WP_CLI::success( 'Import complete with no skips.' );
		}
	}
}
