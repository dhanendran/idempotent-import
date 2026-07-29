<?php

namespace IdempotentImport\Importer;

/**
 * Imports terms.
 *
 * Create phase: creates (or matches, or re-syncs) each term, recording three
 * mappings so later phases can resolve every kind of term reference:
 *   term        source term_taxonomy_id -> dest term_taxonomy_id (post assignments)
 *   term_id     source term_id          -> dest term_id           (parent hierarchy)
 *   ttid_termid source term_taxonomy_id -> dest term_id           (post assignments)
 *
 * With `terms.preserve_ids` the source term_id and term_taxonomy_id are claimed
 * directly, so all three mappings are identities and nothing that references a
 * term by ID — `?cat={ID}`, block attributes such as core/query's taxQuery,
 * ID-bearing meta — has to be rewritten (spec 3.3.1). An occupied ID is reported
 * as a skip, never reissued.
 *
 * Rewrite phase: sets each term's parent (a term_id), re-syncs the columns of a
 * term whose source changed, writes term meta, then aligns the term tables'
 * AUTO_INCREMENT when IDs are being preserved.
 */
class Terms extends AbstractImporter {

	/** Source ttids whose destination row pre-existed and needs its columns re-synced. */
	private $updatedIds = array();

	/** Highest source term_taxonomy_id seen, for the AUTO_INCREMENT fallback. */
	private $highestTtId = 0;

	public function type() {
		return 'term';
	}

	public function createPhase() {
		foreach ( $this->each( 'terms' ) as $entity ) {
			$this->createOne( $entity );
		}
	}

	/**
	 * @param array $entity
	 */
	private function createOne( array $entity ) {
		$srcTtId   = isset( $entity['term_taxonomy_id'] ) ? (int) $entity['term_taxonomy_id'] : 0;
		$srcTermId = isset( $entity['term_id'] ) ? (int) $entity['term_id'] : 0;
		$taxonomy  = isset( $entity['taxonomy'] ) ? (string) $entity['taxonomy'] : '';
		$name      = isset( $entity['name'] ) ? (string) $entity['name'] : '';
		if ( $srcTtId <= 0 || '' === $taxonomy || '' === $name ) {
			return;
		}
		$this->highestTtId = max( $this->highestTtId, $srcTtId );

		$this->fire( 'idempotent_import_before_entity', 'term', $entity );

		$this->restoring = false;
		$hash            = $this->hash( $entity );
		$decision        = $this->ledgerDecision( 'term', $srcTtId, $hash );

		if ( 'new' !== $decision['state'] && ! $this->destinationIntact( 'term', (int) $decision['dest'] ) ) {
			$this->note( 'term', $srcTtId, "restoring: destination term #{$decision['dest']} no longer exists" );
			$this->restoring = true;
		}
		if ( ! $this->restoring && 'unchanged' === $decision['state'] ) {
			$this->note( 'term', $srcTtId, "unchanged #{$decision['dest']} (already imported, nothing to do)" );
			$this->ctx->report->record( 'term', 'unchanged' );
			return;
		}
		if ( ! $this->restoring && 'changed' === $decision['state'] ) {
			// A term the ledger already imported: its name, slug, description and meta
			// are whatever the last run left behind. Re-sync them or the delta cutover
			// (spec 3.3.9) silently drops every editorial change to a term.
			$destTermId = $this->ctx->idMap->ttIdToTermId( $srcTtId );
			if ( 'update' === $this->ctx->onConflict && $destTermId ) {
				$this->writeIds[ (string) $srcTtId ]   = $destTermId;
				$this->updatedIds[ (string) $srcTtId ] = true;
				$this->ctx->idMap->rememberTerm( $srcTtId, (int) $decision['dest'], 'updated', $hash );
				$this->note( 'term', $srcTtId, "updated #{$destTermId} (source changed)" );
				$this->ctx->report->record( 'term', 'updated' );
			} else {
				$this->note( 'term', $srcTtId, "conflict #{$decision['dest']} (source changed; kept destination, use --on-conflict=update)" );
				$this->ctx->report->record( 'term', 'conflict' );
			}
			return;
		}

		// Existing destination term?
		$existing = $this->resolveExisting( 'term', $entity );
		if ( $existing ) {
			$term = $this->ctx->wp->getTermBy( $taxonomy, 'slug', isset( $entity['slug'] ) ? (string) $entity['slug'] : '' );
			if ( $term ) {
				if ( $this->preservingIds() && ( (int) $term['term_id'] !== $srcTermId || (int) $term['term_taxonomy_id'] !== $srcTtId ) ) {
					$this->ctx->logger->skip(
						'term',
						$srcTtId,
						"destination already has {$taxonomy}/{$entity['slug']} at term_id {$term['term_id']}/ttid {$term['term_taxonomy_id']}, not the source's {$srcTermId}/{$srcTtId}; strip seed terms before importing"
					);
					$this->ctx->report->record( 'term', 'skipped' );
					return;
				}
				$this->recordMaps( $srcTtId, $srcTermId, (int) $term['term_taxonomy_id'], (int) $term['term_id'], 'matched', $hash );
				// A pre-existing destination term still needs the snapshot's hierarchy
				// and meta: `parent` is structural, not editorial, and a term nobody
				// wrote meta to has none to keep.
				$this->writeIds[ (string) $srcTtId ]   = (int) $term['term_id'];
				$this->updatedIds[ (string) $srcTtId ] = true;
				$this->note( 'term', $srcTtId, "matched existing #{$term['term_id']} (by {$taxonomy}/slug)" );
				$this->ctx->report->record( 'term', $this->outcome( 'matched' ) );
				return;
			}
		}

		if ( $this->preservingIds() && $this->idCollides( $srcTermId, $srcTtId, $entity ) ) {
			return;
		}

		if ( $this->ctx->dryRun ) {
			$this->note( 'term', $srcTtId, 'would create (no existing match)' );
			$this->ctx->report->record( 'term', $this->outcome( 'created' ) );
			return;
		}

		try {
			$result = $this->insert( $srcTermId, $srcTtId, $name, $taxonomy, $entity );
		} catch ( \Throwable $e ) {
			$this->ctx->logger->skip( 'term', $srcTtId, $e->getMessage() );
			$this->ctx->report->record( 'term', 'skipped' );
			return;
		}

		$this->recordMaps( $srcTtId, $srcTermId, (int) $result['term_taxonomy_id'], (int) $result['term_id'], 'created', $hash );
		$this->writeIds[ (string) $srcTtId ] = (int) $result['term_id'];
		$this->note( 'term', $srcTtId, "created #{$result['term_id']}" );
		$this->ctx->report->record( 'term', $this->outcome( 'created' ) );
		$this->fire( 'idempotent_import_after_entity', 'term', $entity, (int) $result['term_id'] );
	}

	/**
	 * @param int    $srcTermId
	 * @param int    $srcTtId
	 * @param string $name
	 * @param string $taxonomy
	 * @param array  $entity
	 * @return array{term_id:int,term_taxonomy_id:int}
	 */
	private function insert( $srcTermId, $srcTtId, $name, $taxonomy, array $entity ) {
		$args = array(
			'slug'        => isset( $entity['slug'] ) ? (string) $entity['slug'] : '',
			'description' => isset( $entity['description'] ) ? (string) $entity['description'] : '',
		);

		if ( ! $this->preservingIds() ) {
			return $this->ctx->wp->insertTerm( $name, $taxonomy, $this->ctx->decoder->forStorageRow( $args ) );
		}

		// Preserved IDs make the source parent term_id valid on the destination as it
		// stands, so it goes in at insert time rather than in the rewrite phase.
		$args['parent']     = isset( $entity['parent'] ) ? (int) $entity['parent'] : 0;
		$args['count']      = isset( $entity['count'] ) ? (int) $entity['count'] : 0;
		$args['term_group'] = isset( $entity['term_group'] ) ? (int) $entity['term_group'] : 0;

		return $this->ctx->wp->insertTermWithIds(
			$srcTermId,
			$srcTtId,
			$name,
			$taxonomy,
			$this->ctx->decoder->forStorageRow( $args )
		);
	}

	/**
	 * Is either ID this term needs already held by something else?
	 *
	 * The resolver has already adopted the destination term when it is demonstrably
	 * the same one, so anything still sitting on these IDs is unrelated. Reissuing
	 * instead would break every ID-bearing reference silently, so refuse and report
	 * it: what needs fixing is destination prep (spec 3.3.2), not this term.
	 *
	 * A wp_terms row on the source term_id carrying the same slug is not a
	 * collision — that is a term_id shared across taxonomies, and the second
	 * taxonomy row belongs on it.
	 *
	 * @param int   $srcTermId
	 * @param int   $srcTtId
	 * @param array $entity
	 * @return bool True when the term was skipped.
	 */
	private function idCollides( $srcTermId, $srcTtId, array $entity ) {
		$ttRow = $this->ctx->wp->getTermTaxonomyRow( $srcTtId );
		if ( $ttRow ) {
			$this->ctx->logger->skip(
				'term',
				$srcTtId,
				"destination term_taxonomy_id is occupied by unrelated {$ttRow['taxonomy']} term #{$ttRow['term_id']}; strip seed terms before importing"
			);
			$this->ctx->report->record( 'term', 'skipped' );
			return true;
		}

		$slug    = isset( $entity['slug'] ) ? (string) $entity['slug'] : '';
		$termRow = $srcTermId > 0 ? $this->ctx->wp->getTermRow( $srcTermId ) : null;
		if ( $termRow && (string) $termRow['slug'] !== $slug ) {
			$this->ctx->logger->skip(
				'term',
				$srcTtId,
				"destination term_id {$srcTermId} is occupied by unrelated term '{$termRow['slug']}'; strip seed terms before importing"
			);
			$this->ctx->report->record( 'term', 'skipped' );
			return true;
		}

		return false;
	}

	/**
	 * @param int    $srcTtId
	 * @param int    $srcTermId
	 * @param int    $destTtId
	 * @param int    $destTermId
	 * @param string $status
	 * @param string $hash
	 */
	private function recordMaps( $srcTtId, $srcTermId, $destTtId, $destTermId, $status, $hash ) {
		$this->ctx->idMap->rememberTerm( $srcTtId, $destTtId, $status, $hash );
		$this->ctx->idMap->rememberTtIdToTermId( $srcTtId, $destTermId );
		if ( $srcTermId <= 0 ) {
			return;
		}
		// One source term_id reaching two destination term_ids means a shared term was
		// split, so `parent` (a term_id) can no longer be resolved unambiguously. It
		// cannot happen with preserved IDs; without them, say so rather than let the
		// last taxonomy imported quietly win.
		$priorDest = $this->ctx->idMap->termId( $srcTermId );
		if ( $priorDest && $priorDest !== (int) $destTermId ) {
			$this->ctx->logger->warn(
				'term',
				$srcTtId,
				"source term_id {$srcTermId} is shared across taxonomies and was split into destination terms {$priorDest} and {$destTermId}; parent hierarchy may resolve to the wrong one. Re-run with --preserve-ids."
			);
			return;
		}
		$this->ctx->idMap->rememberTermId( $srcTermId, $destTermId );
	}

	public function rewritePhase() {
		if ( $this->ctx->dryRun ) {
			return;
		}
		if ( ! empty( $this->writeIds ) ) {
			foreach ( $this->each( 'terms' ) as $entity ) {
				$srcTtId = isset( $entity['term_taxonomy_id'] ) ? (string) $entity['term_taxonomy_id'] : '';
				if ( '' === $srcTtId || ! isset( $this->writeIds[ $srcTtId ] ) ) {
					continue;
				}
				$this->rewriteTerm( $srcTtId, $this->writeIds[ $srcTtId ], $entity );
			}
		}

		$this->alignAutoIncrement();
	}

	/**
	 * @param string $srcTtId
	 * @param int    $destTermId
	 * @param array  $entity
	 */
	private function rewriteTerm( $srcTtId, $destTermId, array $entity ) {
		$taxonomy = isset( $entity['taxonomy'] ) ? (string) $entity['taxonomy'] : '';
		$fields   = array();

		// A term created this run already carries the snapshot's columns from the
		// insert. One that was matched or re-synced does not.
		if ( isset( $this->updatedIds[ $srcTtId ] ) ) {
			foreach ( array( 'name', 'slug', 'description' ) as $column ) {
				if ( array_key_exists( $column, $entity ) ) {
					$fields[ $column ] = $entity[ $column ];
				}
			}
			$fields = $this->ctx->decoder->forStorageRow( $fields );
			if ( array_key_exists( 'term_group', $entity ) ) {
				$fields['term_group'] = (int) $entity['term_group'];
			}
		}

		// Preserved IDs already had the real parent at insert time (see insert()), so
		// re-writing it would be a redundant wp_update_term per term; only flag a
		// parent the run never imported.
		$srcParent = isset( $entity['parent'] ) ? (int) $entity['parent'] : 0;
		if ( $srcParent > 0 ) {
			$destParent = $this->ctx->idMap->termId( $srcParent );
			if ( ! $destParent ) {
				$this->ctx->logger->warn( 'term', $srcTtId, "parent term_id {$srcParent} not mapped" );
			} elseif ( ! $this->preservingIds() || isset( $this->updatedIds[ $srcTtId ] ) ) {
				$fields['parent'] = $destParent;
			}
		}

		if ( $fields ) {
			try {
				$this->ctx->wp->updateTermFields( $destTermId, $taxonomy, $fields );
			} catch ( \Throwable $e ) {
				$this->ctx->logger->warn( 'term', $srcTtId, 'rewrite update failed: ' . $e->getMessage() );
			}
		}

		$this->writeMeta(
			'term',
			$destTermId,
			$entity,
			function ( $id, $key, $value ) {
				$this->ctx->wp->addTermMeta( $id, $key, $value );
			},
			function ( $id, $key ) {
				$this->ctx->wp->deleteTermMeta( $id, $key );
			}
		);
	}

	/**
	 * Push the term tables' AUTO_INCREMENT past the snapshot's range (spec 3.3.2).
	 * Only meaningful when IDs were preserved; otherwise the destination assigned
	 * them from its own sequence and it is already correct.
	 *
	 * The exporter records wp_terms' AUTO_INCREMENT; snapshots predating it also
	 * recording wp_term_taxonomy's fall back to the highest ttid this run saw.
	 *
	 * @return void
	 */
	private function alignAutoIncrement() {
		if ( ! $this->preservingIds() ) {
			return;
		}
		$nextTtId = $this->ctx->manifest->autoIncrement( 'term_taxonomy' );
		if ( $nextTtId < 1 ) {
			$nextTtId = $this->highestTtId + 1;
		}
		try {
			$this->ctx->wp->setTermsAutoIncrement( $this->ctx->manifest->autoIncrement( 'terms' ), $nextTtId );
		} catch ( \Throwable $e ) {
			$this->ctx->logger->warn( 'term', '*', 'could not set term AUTO_INCREMENT: ' . $e->getMessage() );
		}
	}

	/**
	 * @return bool
	 */
	protected function preservingIds() {
		return (bool) $this->ctx->config->get( 'terms.preserve_ids', false );
	}
}
