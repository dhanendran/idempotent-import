<?php

namespace IdempotentImport\Importer;

use IdempotentImport\PostColumns;

/**
 * Imports posts of every type, including attachments.
 *
 * Create phase: inserts each post's own columns (author resolved now, parent
 * deferred). Attachments are delegated to the configured AttachmentStrategy,
 * which also records a source-URL -> destination-URL mapping for content
 * rewriting.
 *
 * Rewrite phase: sets post_parent, assigns terms, rewrites post_content and
 * writes meta (including _thumbnail_id) for posts created this run. Attachments
 * only have their parent set — WordPress manages their generated metadata.
 */
class Posts extends AbstractImporter {

	/** Source id => dest id for attachments created this run (parent-only rewrite). */
	private $attachmentIds = array();

	public function type() {
		return 'post';
	}

	public function createPhase() {
		foreach ( $this->snapshot->iterate( 'posts' ) as $entity ) {
			$srcId = isset( $entity['ID'] ) ? (int) $entity['ID'] : 0;
			if ( $srcId <= 0 ) {
				continue;
			}
			$this->fire( 'idempotent_import_before_entity', 'post', $entity );

			$isAttachment = isset( $entity['post_type'] ) && 'attachment' === $entity['post_type'];
			if ( $isAttachment ) {
				$this->createAttachment( $srcId, $entity );
			} else {
				$this->createPost( $srcId, $entity );
			}
		}
	}

	/**
	 * @param int   $srcId
	 * @param array $entity
	 */
	private function createPost( $srcId, array $entity ) {
		$hash     = $this->hash( $entity );
		$decision = $this->ledgerDecision( 'post', $srcId, $hash );

		if ( 'unchanged' === $decision['state'] ) {
			$this->ctx->report->record( 'post', 'matched' );
			return;
		}
		if ( 'changed' === $decision['state'] ) {
			if ( 'update' === $this->ctx->onConflict ) {
				$this->writeIds[ (string) $srcId ] = (int) $decision['dest'];
				$this->ctx->idMap->rememberPost( $srcId, (int) $decision['dest'], 'updated', $hash );
				$this->ctx->report->record( 'post', 'updated' );
			} else {
				$this->ctx->report->record( 'post', 'matched' );
			}
			return;
		}

		$existing = $this->resolveExisting( 'post', $entity );
		if ( $existing ) {
			$this->ctx->idMap->rememberPost( $srcId, $existing, 'matched', $hash );
			$this->ctx->report->record( 'post', 'matched' );
			return;
		}

		if ( $this->ctx->dryRun ) {
			$this->ctx->report->record( 'post', 'created' );
			return;
		}

		$cols = PostColumns::fromEntity( $entity, $this->ctx );
		try {
			$destId = $this->ctx->wp->insertPost( $this->ctx->decoder->forStorageRow( $cols ) );
		} catch ( \Throwable $e ) {
			$this->ctx->logger->skip( 'post', $srcId, $e->getMessage() );
			$this->ctx->report->record( 'post', 'skipped' );
			return;
		}

		$this->ctx->idMap->rememberPost( $srcId, $destId, 'created', $hash );
		$this->writeIds[ (string) $srcId ] = $destId;
		$this->ctx->report->record( 'post', 'created' );
		$this->fire( 'idempotent_import_after_entity', 'post', $entity, $destId );
	}

	/**
	 * @param int   $srcId
	 * @param array $entity
	 */
	private function createAttachment( $srcId, array $entity ) {
		$hash     = $this->hash( $entity );
		$decision = $this->ledgerDecision( 'post', $srcId, $hash );
		if ( 'new' !== $decision['state'] ) {
			$this->ctx->report->record( 'attachment', 'matched' );
			return;
		}

		$existing = $this->resolveExisting( 'post', $entity );
		if ( $existing ) {
			$this->ctx->idMap->rememberPost( $srcId, $existing, 'matched', $hash );
			$this->mapUrl( $entity, $this->ctx->wp->getAttachmentUrl( $existing ) );
			$this->ctx->report->record( 'attachment', 'matched' );
			return;
		}

		if ( $this->ctx->dryRun ) {
			$this->ctx->report->record( 'attachment', 'created' );
			return;
		}

		$strategy = $this->pickStrategy( $entity );
		try {
			$result = $strategy->import( $entity, $this->ctx );
		} catch ( \Throwable $e ) {
			$this->ctx->logger->skip( 'attachment', $srcId, $e->getMessage() );
			$this->ctx->report->record( 'attachment', 'skipped' );
			return;
		}

		if ( null === $result->destId ) {
			$this->ctx->logger->skip( 'attachment', $srcId, 'attachment strategy produced no id' );
			$this->ctx->report->record( 'attachment', 'skipped' );
			return;
		}

		$this->ctx->idMap->rememberPost( $srcId, $result->destId, $result->outcome, $hash );
		$this->mapUrl( $entity, $result->destUrl );
		$this->attachmentIds[ (string) $srcId ] = (int) $result->destId;
		$this->ctx->report->record( 'attachment', $result->outcome );
		$this->fire( 'idempotent_import_after_entity', 'post', $entity, (int) $result->destId );
	}

	/**
	 * @param array       $entity
	 * @param string|null $destUrl
	 */
	private function mapUrl( array $entity, $destUrl ) {
		$srcUrl = isset( $entity['attachment_url'] ) ? (string) $entity['attachment_url'] : '';
		if ( '' !== $srcUrl && $destUrl ) {
			$this->ctx->idMap->rememberUrl( $srcUrl, $destUrl );
		}
	}

	/**
	 * @param array $entity
	 * @return \IdempotentImport\Contracts\AttachmentStrategy
	 */
	private function pickStrategy( array $entity ) {
		$name = (string) $this->ctx->config->get( 'attachments.strategy', 'sideload' );
		if ( function_exists( 'apply_filters' ) ) {
			$name = (string) apply_filters( 'idempotent_import_attachment_strategy', $name, $entity, $this->ctx );
		}
		$strategy = $this->registry->attachmentStrategy( $name );
		if ( ! $strategy ) {
			$strategy = $this->registry->attachmentStrategy( 'sideload' );
		}
		return $strategy;
	}

	public function rewritePhase() {
		if ( $this->ctx->dryRun ) {
			return;
		}
		foreach ( $this->snapshot->iterate( 'posts' ) as $entity ) {
			$srcId = isset( $entity['ID'] ) ? (string) $entity['ID'] : '';
			if ( '' === $srcId ) {
				continue;
			}
			if ( isset( $this->writeIds[ $srcId ] ) ) {
				$this->rewritePost( (int) $srcId, $this->writeIds[ $srcId ], $entity );
			} elseif ( isset( $this->attachmentIds[ $srcId ] ) ) {
				$this->rewriteAttachmentParent( (int) $srcId, $this->attachmentIds[ $srcId ], $entity );
			}
		}
	}

	/**
	 * @param int   $srcId
	 * @param int   $destId
	 * @param array $entity
	 */
	private function rewritePost( $srcId, $destId, array $entity ) {
		$fields = array();

		$srcParent = isset( $entity['post_parent'] ) ? (int) $entity['post_parent'] : 0;
		if ( $srcParent > 0 ) {
			$destParent = $this->ctx->idMap->post( $srcParent );
			if ( $destParent ) {
				$fields['post_parent'] = $destParent;
			} else {
				$this->ctx->logger->warn( 'post', $srcId, "post_parent {$srcParent} not mapped" );
			}
		}

		$rewriter = $this->registry->contentRewriter();
		if ( $rewriter && isset( $entity['post_content'] ) ) {
			$new = $rewriter->rewrite( (string) $entity['post_content'], $entity, $this->ctx );
			if ( $new !== $entity['post_content'] ) {
				$fields['post_content'] = $this->ctx->decoder->forStorageValue( $new );
			}
		}

		if ( $fields ) {
			try {
				$this->ctx->wp->updatePostFields( $destId, $fields );
			} catch ( \Throwable $e ) {
				$this->ctx->logger->warn( 'post', $srcId, 'rewrite update failed: ' . $e->getMessage() );
			}
		}

		$this->assignTerms( $destId, $entity );

		$this->writeMeta(
			'post',
			$destId,
			$entity,
			function ( $id, $key, $value ) {
				$this->ctx->wp->addPostMeta( $id, $key, $value );
			}
		);
	}

	/**
	 * @param int   $destId
	 * @param array $entity
	 */
	private function assignTerms( $destId, array $entity ) {
		$terms = isset( $entity['terms'] ) && is_array( $entity['terms'] ) ? $entity['terms'] : array();
		foreach ( $terms as $taxonomy => $srcTtIds ) {
			$destTermIds = array();
			foreach ( (array) $srcTtIds as $srcTtId ) {
				$mapped = $this->ctx->idMap->ttIdToTermId( (int) $srcTtId );
				if ( $mapped ) {
					$destTermIds[] = $mapped;
				}
			}
			if ( $destTermIds ) {
				try {
					$this->ctx->wp->setPostTerms( $destId, (string) $taxonomy, $destTermIds, false );
				} catch ( \Throwable $e ) {
					$this->ctx->logger->warn( 'post', $destId, "term assignment failed for {$taxonomy}: " . $e->getMessage() );
				}
			}
		}
	}

	/**
	 * @param int   $srcId
	 * @param int   $destId
	 * @param array $entity
	 */
	private function rewriteAttachmentParent( $srcId, $destId, array $entity ) {
		$srcParent = isset( $entity['post_parent'] ) ? (int) $entity['post_parent'] : 0;
		if ( $srcParent <= 0 ) {
			return;
		}
		$destParent = $this->ctx->idMap->post( $srcParent );
		if ( $destParent ) {
			try {
				$this->ctx->wp->updatePostFields( $destId, array( 'post_parent' => $destParent ) );
			} catch ( \Throwable $e ) {
				$this->ctx->logger->warn( 'attachment', $srcId, 'parent update failed: ' . $e->getMessage() );
			}
		}
	}
}
