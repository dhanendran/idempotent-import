<?php

namespace IdempotentImport\Importer;

use IdempotentImport\Contracts\WordPress;
use IdempotentImport\PostColumns;

/**
 * Imports posts of every type, including attachments.
 *
 * Create phase: inserts each post's own columns (author resolved now; parent
 * deferred to the rewrite phase, except when IDs are preserved and it is already
 * known — see PostColumns). Attachments are delegated to the configured
 * AttachmentStrategy, which also records a source-URL -> destination-URL mapping
 * for content rewriting.
 *
 * Rewrite phase: resolves post_parent, assigns terms, rewrites post_content and
 * writes meta (including _thumbnail_id) for posts created or updated this run,
 * then aligns the posts AUTO_INCREMENT when IDs are being preserved.
 */
class Posts extends AbstractImporter {

	/**
	 * Meta describing the binary rather than the post. When the destination
	 * sideloaded its own copy of a file it derived these itself, and the source
	 * values would describe a file that is not there.
	 */
	const BINARY_META = array( '_wp_attached_file', '_wp_attachment_metadata' );

	/** Source id => dest id for attachments created this run. */
	private $attachmentIds = array();

	/** Source id => AttachmentResult outcome, deciding how much meta to write. */
	private $attachmentOutcomes = array();

	/** Source ids whose destination row pre-existed and needs its columns re-synced. */
	private $updatedIds = array();

	public function type() {
		return 'post';
	}

	public function createPhase() {
		$this->warnIfContentWillBeFiltered();

		foreach ( $this->each( 'posts' ) as $entity ) {
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
		$this->restoring = false;
		$hash            = $this->hash( $entity );
		$decision        = $this->ledgerDecision( 'post', $srcId, $hash );

		if ( 'new' !== $decision['state'] && ! $this->destinationIntact( 'post', (int) $decision['dest'] ) ) {
			$this->note( 'post', $srcId, "restoring: destination post #{$decision['dest']} no longer exists" );
			$this->restoring = true;
		}
		if ( ! $this->restoring && 'unchanged' === $decision['state'] ) {
			$this->note( 'post', $srcId, "unchanged #{$decision['dest']} (already imported, nothing to do)" );
			$this->ctx->report->record( 'post', 'unchanged' );
			return;
		}
		if ( ! $this->restoring && 'changed' === $decision['state'] ) {
			if ( 'update' === $this->ctx->onConflict ) {
				$this->writeIds[ (string) $srcId ]   = (int) $decision['dest'];
				$this->updatedIds[ (string) $srcId ] = true;
				$this->ctx->idMap->rememberPost( $srcId, (int) $decision['dest'], 'updated', $hash );
				$this->note( 'post', $srcId, "updated #{$decision['dest']} (content changed)" );
				$this->ctx->report->record( 'post', 'updated' );
			} else {
				$this->note( 'post', $srcId, "conflict #{$decision['dest']} (source changed; kept destination, use --on-conflict=update)" );
				$this->ctx->report->record( 'post', 'conflict' );
			}
			return;
		}

		$existing = $this->resolveExisting( 'post', $entity );
		if ( $existing ) {
			$this->ctx->idMap->rememberPost( $srcId, $existing, 'matched', $hash );
			$this->note( 'post', $srcId, "matched existing #{$existing} (by post_name/guid)" );
			$this->ctx->report->record( 'post', $this->outcome( 'matched' ) );
			return;
		}

		if ( $this->idCollides( 'post', $srcId ) ) {
			return;
		}

		if ( $this->ctx->dryRun ) {
			$this->note( 'post', $srcId, 'would create (no existing match)' );
			$this->ctx->report->record( 'post', $this->outcome( 'created' ) );
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

		if ( $this->idNotPreserved( 'post', $srcId, $destId ) ) {
			return;
		}

		$this->ctx->idMap->rememberPost( $srcId, $destId, 'created', $hash );
		$this->writeIds[ (string) $srcId ] = $destId;
		$this->note( 'post', $srcId, "created #{$destId}" );
		$this->ctx->report->record( 'post', $this->outcome( 'created' ) );
		$this->fire( 'idempotent_import_after_entity', 'post', $entity, $destId );
	}

	/**
	 * @param int   $srcId
	 * @param array $entity
	 */
	private function createAttachment( $srcId, array $entity ) {
		$this->restoring = false;
		$hash            = $this->hash( $entity );
		$decision        = $this->ledgerDecision( 'post', $srcId, $hash );
		if ( 'new' !== $decision['state'] ) {
			if ( ! $this->destinationIntact( 'post', (int) $decision['dest'] ) ) {
				$this->note( 'attachment', $srcId, "restoring: destination attachment #{$decision['dest']} no longer exists" );
				$this->restoring = true;
			} elseif ( 'unchanged' === $decision['state'] ) {
				$this->note( 'attachment', $srcId, "unchanged #{$decision['dest']} (already imported, nothing to do)" );
				$this->ctx->report->record( 'attachment', 'unchanged' );
				return;
			} elseif ( 'update' === $this->ctx->onConflict ) {
				// Re-sync the record, not the binary: alt text and captions are editorial
				// content and a delta cutover has to carry them over.
				$destId                                      = (int) $decision['dest'];
				$this->attachmentIds[ (string) $srcId ]      = $destId;
				$this->attachmentOutcomes[ (string) $srcId ] = 'updated';
				$this->ctx->idMap->rememberPost( $srcId, $destId, 'updated', $hash );
				$this->note( 'attachment', $srcId, "updated #{$destId} (source changed)" );
				$this->ctx->report->record( 'attachment', 'updated' );
				return;
			} else {
				$this->note( 'attachment', $srcId, "conflict #{$decision['dest']} (source changed; kept destination, use --on-conflict=update)" );
				$this->ctx->report->record( 'attachment', 'conflict' );
				return;
			}
		}

		$existing = $this->resolveExisting( 'post', $entity );
		if ( $existing ) {
			$this->ctx->idMap->rememberPost( $srcId, $existing, 'matched', $hash );
			$this->mapUrl( $entity, $this->ctx->wp->getAttachmentUrl( $existing ) );
			$this->ctx->report->record( 'attachment', $this->outcome( 'matched' ) );
			return;
		}

		if ( $this->idCollides( 'attachment', $srcId ) ) {
			return;
		}

		if ( $this->ctx->dryRun ) {
			$this->ctx->report->record( 'attachment', $this->outcome( 'created' ) );
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

		// map-existing deliberately adopts a different id; every other strategy has
		// to land on the source id, or attachment URLs and _thumbnail_id drift.
		if ( 'matched' !== $result->outcome && $this->idNotPreserved( 'attachment', $srcId, $result->destId ) ) {
			return;
		}

		$this->ctx->idMap->rememberPost( $srcId, $result->destId, $result->outcome, $hash );
		$this->mapUrl( $entity, $result->destUrl );
		$this->attachmentIds[ (string) $srcId ]      = (int) $result->destId;
		$this->attachmentOutcomes[ (string) $srcId ] = (string) $result->outcome;

		// `referenced` is a creation — a new attachment post, just without a copied
		// binary. Recording it verbatim files it under an outcome the summary never
		// prints, which silently hides the whole attachment count from reconciliation.
		$reported = 'referenced' === $result->outcome ? 'created' : $result->outcome;
		$this->ctx->report->record( 'attachment', $this->outcome( $reported ) );
		$this->fire( 'idempotent_import_after_entity', 'post', $entity, (int) $result->destId );
	}

	/**
	 * Is the destination ID this entity needs occupied by something else?
	 *
	 * The resolver adopts the row at a source ID when it is demonstrably the same
	 * post, so anything still sitting there is unrelated content. Reissuing an ID
	 * instead would break URL integrity silently, so refuse and report it: what
	 * needs fixing is destination prep, not this entity.
	 *
	 * @param string $type post|attachment (report bucket).
	 * @param int    $srcId
	 * @return bool True when the entity was skipped.
	 */
	private function idCollides( $type, $srcId ) {
		if ( ! PostColumns::preservingIds( $this->ctx ) || ! $this->ctx->wp->getPost( $srcId ) ) {
			return false;
		}
		$this->ctx->logger->skip( $type, $srcId, 'destination ID is occupied by unrelated content; strip seed content before importing' );
		$this->ctx->report->record( $type, 'skipped' );
		return true;
	}

	/**
	 * wp_insert_post() ignores import_id silently rather than failing, so a
	 * preserved ID is only real once confirmed against what came back.
	 *
	 * @param string $type post|attachment (report bucket).
	 * @param int    $srcId
	 * @param int    $destId
	 * @return bool True when the entity was skipped.
	 */
	private function idNotPreserved( $type, $srcId, $destId ) {
		if ( ! PostColumns::preservingIds( $this->ctx ) || (int) $destId === (int) $srcId ) {
			return false;
		}
		$this->ctx->logger->skip( $type, $srcId, "preserved ID was refused; destination created #{$destId} instead — remove that row and re-run" );
		$this->ctx->report->record( $type, 'skipped' );
		return true;
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
		foreach ( $this->each( 'posts' ) as $entity ) {
			$srcId = isset( $entity['ID'] ) ? (string) $entity['ID'] : '';
			if ( '' === $srcId ) {
				continue;
			}
			if ( isset( $this->writeIds[ $srcId ] ) ) {
				$this->rewritePost( (int) $srcId, $this->writeIds[ $srcId ], $entity );
			} elseif ( isset( $this->attachmentIds[ $srcId ] ) ) {
				$this->rewriteAttachment( (int) $srcId, $this->attachmentIds[ $srcId ], $entity );
			}
		}

		$this->alignAutoIncrement();
	}

	/**
	 * @param int   $srcId
	 * @param int   $destId
	 * @param array $entity
	 */
	private function rewritePost( $srcId, $destId, array $entity ) {
		// A post created this run already carries the snapshot's columns from the
		// insert. One the ledger matched to an existing row does not: its title,
		// status, dates and content are whatever the previous import left behind,
		// so re-sync the whole column set or the delta cutover silently loses edits.
		$fields = array();
		if ( isset( $this->updatedIds[ (string) $srcId ] ) ) {
			$cols = PostColumns::fromEntity( $entity, $this->ctx );
			unset( $cols['import_id'], $cols['post_parent'] );
			$fields = $this->ctx->decoder->forStorageRow( $cols );
		}

		// Preserved IDs already had the real parent at insert time (see PostColumns),
		// so re-writing it here would be a redundant wp_update_post per post; only
		// flag a parent the run never imported.
		$srcParent = isset( $entity['post_parent'] ) ? (int) $entity['post_parent'] : 0;
		if ( $srcParent > 0 ) {
			$destParent = $this->ctx->idMap->post( $srcParent );
			if ( ! $destParent ) {
				$this->ctx->logger->warn( 'post', $srcId, "post_parent {$srcParent} not mapped" );
			} elseif ( ! PostColumns::preservingIds( $this->ctx ) ) {
				$fields['post_parent'] = $destParent;
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
			// wp_update_post() re-derives status, slug and post_modified from scratch,
			// so restate the snapshot's values and let Wp pin them — otherwise a post
			// the insert got right is spoiled by the rewrite that follows it.
			$fields += $this->preservedColumns( $entity );
			try {
				$this->ctx->wp->updatePostFields( $destId, $fields );
			} catch ( \Throwable $e ) {
				$this->ctx->logger->warn( 'post', $srcId, 'rewrite update failed: ' . $e->getMessage() );
			}
		}

		$this->assignTerms( $destId, $entity );
		$this->writePostMeta( $destId, $entity );
	}

	/**
	 * Write a post's meta, pruning anything the snapshot does not have.
	 *
	 * Pruning matters in both directions: WordPress seeds its own keys on insert
	 * (_pingme, _encloseme) that the source never had, and a key deleted at the
	 * source would otherwise survive every future delta run.
	 *
	 * @param int      $destId
	 * @param array    $entity
	 * @param string[] $exempt Destination keys to leave alone rather than prune,
	 *                         for meta this run deliberately withheld.
	 * @return void
	 */
	private function writePostMeta( $destId, array $entity, array $exempt = array() ) {
		$destKeys = array_values( array_diff( $this->ctx->wp->postMetaKeys( $destId ), $exempt ) );

		$this->writeMeta(
			'post',
			$destId,
			$entity,
			function ( $id, $key, $value ) {
				$this->ctx->wp->addPostMeta( $id, $key, $value );
			},
			function ( $id, $key ) {
				$this->ctx->wp->deletePostMeta( $id, $key );
			},
			$destKeys
		);
	}

	/**
	 * The snapshot's values for the columns WordPress would otherwise re-derive,
	 * ready to hand to an update (see WordPress::PRESERVED_COLUMNS).
	 *
	 * @param array $entity
	 * @return array
	 */
	private function preservedColumns( array $entity ) {
		$cols = array();
		foreach ( WordPress::PRESERVED_COLUMNS as $column ) {
			if ( array_key_exists( $column, $entity ) ) {
				$cols[ $column ] = $entity[ $column ];
			}
		}
		return $this->ctx->decoder->forStorageRow( $cols );
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
	 * Reattach an attachment to its parent and write its meta.
	 *
	 * Attachment meta is content, not just plumbing: _wp_attachment_image_alt is
	 * editorial text and dropping it is an accessibility regression. Only the
	 * binary-derived keys are withheld, and only when the destination sideloaded
	 * its own file and therefore generated better ones.
	 *
	 * @param int   $srcId
	 * @param int   $destId
	 * @param array $entity
	 */
	private function rewriteAttachment( $srcId, $destId, array $entity ) {
		$outcome = isset( $this->attachmentOutcomes[ (string) $srcId ] ) ? $this->attachmentOutcomes[ (string) $srcId ] : '';

		// An attachment re-synced from a changed source needs its own columns back
		// too — title, caption (post_excerpt) and description (post_content) are all
		// editorial. The binary is deliberately left alone; see below.
		$fields = array();
		if ( 'updated' === $outcome ) {
			$cols = PostColumns::fromEntity( $entity, $this->ctx );
			unset( $cols['import_id'], $cols['post_parent'] );
			$fields = $this->ctx->decoder->forStorageRow( $cols );
		}

		$srcParent = isset( $entity['post_parent'] ) ? (int) $entity['post_parent'] : 0;
		if ( $srcParent > 0 ) {
			$destParent = $this->ctx->idMap->post( $srcParent );
			if ( $destParent ) {
				$fields['post_parent'] = $destParent;
			}
		}

		if ( $fields ) {
			$fields += $this->preservedColumns( $entity );
			try {
				$this->ctx->wp->updatePostFields( $destId, $fields );
			} catch ( \Throwable $e ) {
				$this->ctx->logger->warn( 'attachment', $srcId, 'rewrite update failed: ' . $e->getMessage() );
			}
		}

		if ( 'matched' === $outcome ) {
			return; // Pre-existing destination media: leave its own meta alone.
		}
		// Meta describing the binary belongs to whichever file the destination
		// actually holds. Only `referenced` points at the source file and can carry
		// the source's values; a sideload derived its own, and an update never
		// re-downloaded, so in both cases the destination's own values must stand.
		$withheld = array();
		if ( 'referenced' !== $outcome && isset( $entity['meta'] ) && is_array( $entity['meta'] ) ) {
			$entity['meta'] = array_diff_key( $entity['meta'], array_flip( self::BINARY_META ) );
			$withheld       = self::BINARY_META;
		}

		$this->writePostMeta( $destId, $entity, $withheld );
	}

	/**
	 * Push the posts AUTO_INCREMENT past the snapshot's range (spec 3.3.2) so
	 * content created after the migration cannot reuse a migrated ID. Only
	 * meaningful when IDs were preserved; otherwise the destination assigned them
	 * from its own sequence and it is already correct.
	 *
	 * @return void
	 */
	private function alignAutoIncrement() {
		if ( ! PostColumns::preservingIds( $this->ctx ) ) {
			return;
		}
		try {
			$this->ctx->wp->setPostsAutoIncrement( $this->ctx->manifest->autoIncrement( 'posts' ) );
		} catch ( \Throwable $e ) {
			$this->ctx->logger->warn( 'post', '*', 'could not set posts AUTO_INCREMENT: ' . $e->getMessage() );
		}
	}

	/**
	 * Everything is written through wp_insert_post() with filters live, so without
	 * unfiltered_html kses strips iframes and scripts out of legacy embeds — a
	 * silent content change that only shows up as a diff on re-export.
	 *
	 * @return void
	 */
	private function warnIfContentWillBeFiltered() {
		if ( $this->ctx->dryRun || ! function_exists( 'current_user_can' ) ) {
			return;
		}
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$this->ctx->logger->warn(
				'post',
				'*',
				'current user lacks unfiltered_html: kses will strip iframes/scripts from post_content. Re-run with --user=<super-admin>.'
			);
		}
	}
}
