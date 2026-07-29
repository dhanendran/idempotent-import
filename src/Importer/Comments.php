<?php

namespace IdempotentImport\Importer;

/**
 * Imports comments.
 *
 * Create phase: inserts each comment with its post and author already mapped
 * (posts and users are imported first); comment_parent is deferred because it
 * may reference a comment created later in the same run.
 *
 * Rewrite phase: sets comment_parent and writes comment meta for comments
 * created this run.
 */
class Comments extends AbstractImporter {

	/** Columns copied verbatim from the source comment row. */
	const COLUMNS = array(
		'comment_author',
		'comment_author_email',
		'comment_author_url',
		'comment_author_IP',
		'comment_date',
		'comment_date_gmt',
		'comment_content',
		'comment_karma',
		'comment_approved',
		'comment_agent',
		'comment_type',
	);

	public function type() {
		return 'comment';
	}

	public function createPhase() {
		foreach ( $this->each( 'comments' ) as $entity ) {
			$srcId = isset( $entity['comment_ID'] ) ? (int) $entity['comment_ID'] : 0;
			if ( $srcId <= 0 ) {
				continue;
			}
			$this->fire( 'idempotent_import_before_entity', 'comment', $entity );
			$this->createOne( $srcId, $entity );
		}
	}

	/**
	 * @param int   $srcId
	 * @param array $entity
	 */
	private function createOne( $srcId, array $entity ) {
		$this->restoring = false;
		$hash            = $this->hash( $entity );
		$decision        = $this->ledgerDecision( 'comment', $srcId, $hash );
		if ( 'new' !== $decision['state'] ) {
			if ( $this->destinationIntact( 'comment', (int) $decision['dest'] ) ) {
				$this->note( 'comment', $srcId, "unchanged #{$decision['dest']} (already imported, nothing to do)" );
				$this->ctx->report->record( 'comment', 'unchanged' );
				return;
			}
			$this->note( 'comment', $srcId, "restoring: destination comment #{$decision['dest']} no longer exists" );
			$this->restoring = true;
		}

		$srcPost  = isset( $entity['comment_post_ID'] ) ? (int) $entity['comment_post_ID'] : 0;
		$destPost = $this->ctx->idMap->post( $srcPost );
		if ( ! $destPost ) {
			$this->ctx->logger->skip( 'comment', $srcId, "parent post {$srcPost} not mapped" );
			$this->ctx->report->record( 'comment', 'skipped' );
			return;
		}

		$existing = $this->resolveExisting( 'comment', $entity );
		if ( $existing ) {
			if ( ! $this->ctx->dryRun ) {
				$this->ctx->idMap->rememberComment( $srcId, $existing, 'matched', $hash );
			}
			$this->note( 'comment', $srcId, "matched existing #{$existing} (by post/author/date)" );
			$this->ctx->report->record( 'comment', $this->outcome( 'matched' ) );
			return;
		}

		if ( $this->ctx->dryRun ) {
			$this->note( 'comment', $srcId, 'would create (no existing match)' );
			$this->ctx->report->record( 'comment', $this->outcome( 'created' ) );
			return;
		}

		$cols = array();
		foreach ( self::COLUMNS as $c ) {
			if ( array_key_exists( $c, $entity ) ) {
				$cols[ $c ] = $entity[ $c ];
			}
		}
		$cols['comment_post_ID'] = $destPost;
		$cols['comment_parent']  = 0; // Deferred to rewrite phase.

		$srcUser              = isset( $entity['user_id'] ) ? (int) $entity['user_id'] : 0;
		$cols['user_id']      = $srcUser > 0 ? (int) ( $this->ctx->idMap->user( $srcUser ) ?: 0 ) : 0;

		try {
			$destId = $this->ctx->wp->insertComment( $this->ctx->decoder->forStorageRow( $cols ) );
		} catch ( \Throwable $e ) {
			$this->ctx->logger->skip( 'comment', $srcId, $e->getMessage() );
			$this->ctx->report->record( 'comment', 'skipped' );
			return;
		}

		$this->ctx->idMap->rememberComment( $srcId, $destId, 'created', $hash );
		$this->writeIds[ (string) $srcId ] = $destId;
		$this->note( 'comment', $srcId, "created #{$destId}" );
		$this->ctx->report->record( 'comment', $this->outcome( 'created' ) );
		$this->fire( 'idempotent_import_after_entity', 'comment', $entity, $destId );
	}

	public function rewritePhase() {
		if ( $this->ctx->dryRun || empty( $this->writeIds ) ) {
			return;
		}
		foreach ( $this->each( 'comments' ) as $entity ) {
			$srcId = isset( $entity['comment_ID'] ) ? (string) $entity['comment_ID'] : '';
			if ( '' === $srcId || ! isset( $this->writeIds[ $srcId ] ) ) {
				continue;
			}
			$destId = $this->writeIds[ $srcId ];

			$srcParent = isset( $entity['comment_parent'] ) ? (int) $entity['comment_parent'] : 0;
			if ( $srcParent > 0 ) {
				$destParent = $this->ctx->idMap->comment( $srcParent );
				if ( $destParent ) {
					$this->ctx->wp->updateCommentFields( $destId, array( 'comment_parent' => $destParent ) );
				} else {
					$this->ctx->logger->warn( 'comment', $srcId, "comment_parent {$srcParent} not mapped" );
				}
			}

			$this->writeMeta(
				'comment',
				$destId,
				$entity,
				function ( $id, $key, $value ) {
					$this->ctx->wp->addCommentMeta( $id, $key, $value );
				},
				function ( $id, $key ) {
					$this->ctx->wp->deleteCommentMeta( $id, $key );
				}
			);
		}
	}
}
