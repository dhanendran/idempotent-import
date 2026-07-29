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
		$dryRun  = ! empty( $assoc_args['dry-run'] );
		$quiet   = ! empty( $assoc_args['quiet'] );
		$verbose = ! empty( $assoc_args['verbose'] );

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
		if ( $verbose ) {
			$logger->setEcho(
				function ( $line ) {
					\WP_CLI::log( '  ' . $line );
				}
			);
		}

		$report  = new Report( $sourceKey, $manifest->source(), $this->provenance( $assoc_args ) );
		$decoder = new Decoder();
		$wp      = new Wp();

		$ctx             = new Context( $wp, $idMap, $config, $logger, $decoder, $manifest, $report );
		$ctx->phase      = 'create';
		$ctx->onConflict = isset( $assoc_args['on-conflict'] ) ? (string) $assoc_args['on-conflict'] : 'update';
		$ctx->dryRun     = $dryRun;
		$ctx->verbose    = $verbose;

		$registry = Bootstrap::defaultRegistry();
		Bootstrap::applyProjectExtensions( $registry, $ctx );

		$importers = $this->buildImporters( $snapshot, $ctx, $registry, $assoc_args );

		$this->announceStart( $snapshotDir, $sourceKey, $dryRun, $blogId );

		if ( function_exists( 'wp_suspend_cache_addition' ) ) {
			wp_suspend_cache_addition( true );
		}
		$priorHasher = $dryRun ? false : $this->useFastPasswordHashing();
		$started     = microtime( true );

		try {
			// Phase 1: create everything, populate the IdMap.
			foreach ( $importers as $importer ) {
				$importer->createPhase();
			}

			// Phase 2: rewrite references now the IdMap is complete.
			$ctx->phase = 'rewrite';
			foreach ( $importers as $importer ) {
				$importer->rewritePhase();
			}
		} finally {
			// Restore even if an importer threw, so the swap cannot outlive the run.
			if ( ! $dryRun ) {
				$this->restorePasswordHashing( $priorHasher );
			}
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
	 * Swap in a cheap password hasher for the duration of the run. Imported users
	 * get a throwaway random password and are forced to reset, so the default bcrypt
	 * cost is pure waste and dominates import time at scale. Measured on WP 7.0.2:
	 * 183.9ms/hash by default, 1.0ms with the swap.
	 *
	 * The weaker hash is not a weakening here: the value hashed is a random 32-char
	 * password nobody holds, and WordPress rehashes with the current algorithm on the
	 * first successful login after a reset.
	 *
	 * This deliberately uses $wp_hasher, which core began retiring in 6.8 when
	 * wp_hash_password() moved to bcrypt. As of 7.0 that function still short-circuits
	 * on the global, so the swap holds. When it stops — or class-phpass.php goes — the
	 * class_exists() guard below makes this a silent no-op and imports fall back to
	 * bcrypt: correct, but ~180x slower per created user. Re-measure before assuming
	 * a slow import is something else.
	 *
	 * Only created users are hashed at all; a run where every user matches an existing
	 * account never calls this path's beneficiary.
	 *
	 * @return mixed The previous $wp_hasher, to hand back to restorePasswordHashing().
	 */
	private function useFastPasswordHashing() {
		if ( ! class_exists( 'PasswordHash' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . WPINC . '/class-phpass.php';
		}
		$prior = isset( $GLOBALS['wp_hasher'] ) ? $GLOBALS['wp_hasher'] : null;
		if ( class_exists( 'PasswordHash' ) ) {
			$GLOBALS['wp_hasher'] = new \PasswordHash( 8, true );
		}
		return $prior;
	}

	/**
	 * @param mixed $prior The value returned by useFastPasswordHashing().
	 * @return void
	 */
	private function restorePasswordHashing( $prior ) {
		$GLOBALS['wp_hasher'] = $prior;
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
		$labels    = $this->outcomeLabels( $dryRun );
		$total     = 0;
		$unchanged = 0;

		\WP_CLI::log( $dryRun ? 'Dry-run summary (nothing written):' : 'Import summary:' );
		foreach ( $report->outcomes() as $type => $counts ) {
			\WP_CLI::log( sprintf( '  %ss — %d total', $type, array_sum( $counts ) ) );
			foreach ( $labels as $outcome => $meaning ) {
				$n = isset( $counts[ $outcome ] ) ? (int) $counts[ $outcome ] : 0;
				\WP_CLI::log( sprintf( '      %-9s %6d   %s', $outcome, $n, $meaning ) );
			}
			$total     += array_sum( $counts );
			$unchanged += isset( $counts['unchanged'] ) ? (int) $counts['unchanged'] : 0;
		}
		\WP_CLI::log( sprintf( 'Warnings: %d. Elapsed: %.2fs.', $logger->warnCount(), $elapsed ) );

		if ( $dryRun || $logger->skipCount() > 0 ) {
			return;
		}
		// Every entity was a no-op: the destination already matches the snapshot.
		if ( $total > 0 && $total === $unchanged ) {
			\WP_CLI::success( sprintf( 'Nothing to do: all %d entities were already imported and unchanged.', $unchanged ) );
			return;
		}
		\WP_CLI::success( 'Import complete with no skips.' );
	}

	/**
	 * The meaning printed beside each outcome count, in the order the importers
	 * decide them. Every outcome gets its own line — collapsing them hides the
	 * difference between "created 501 users" and "501 were already there".
	 *
	 * @param bool $dryRun
	 * @return array<string,string>
	 */
	private function outcomeLabels( $dryRun ) {
		if ( $dryRun ) {
			return array(
				'created'   => '(would create: nothing in the destination matches)',
				'matched'   => '(would link to existing destination content)',
				'updated'   => '(would re-sync: source changed since the last import)',
				'restored'  => '(would re-import: recorded as imported but missing from the destination)',
				'unchanged' => '(no-op: already imported, source unchanged)',
				'conflict'  => '(would keep destination as-is: source changed, --on-conflict is not "update")',
				'skipped'   => '(would NOT import: excluded by a rule, or unresolvable)',
			);
		}
		return array(
			'created'   => '(new: inserted into the destination)',
			'matched'   => '(linked to existing destination content, first time)',
			'updated'   => '(re-synced: source changed since the last import)',
			'restored'  => '(re-imported: recorded as imported but missing from the destination)',
			'unchanged' => '(no-op: already imported, source unchanged)',
			'conflict'  => '(kept destination as-is: source changed, --on-conflict is not "update")',
			'skipped'   => '(NOT imported: excluded by a rule, or failed)',
		);
	}
}
