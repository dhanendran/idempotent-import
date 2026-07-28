<?php

namespace IdempotentImport\Importer;

/**
 * Imports terms.
 *
 * Create phase: creates (or matches) each term without its parent, recording
 * three mappings so later phases can resolve every kind of term reference:
 *   term        source term_taxonomy_id -> dest term_taxonomy_id (post assignments)
 *   term_id     source term_id          -> dest term_id           (parent hierarchy)
 *   ttid_termid source term_taxonomy_id -> dest term_id           (post assignments)
 *
 * Rewrite phase: sets each created term's parent (a term_id) and writes term
 * meta.
 */
class Terms extends AbstractImporter {

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

		$this->fire( 'idempotent_import_before_entity', 'term', $entity );

		$this->restoring = false;
		$hash            = $this->hash( $entity );
		$decision        = $this->ledgerDecision( 'term', $srcTtId, $hash );
		if ( 'new' !== $decision['state'] ) {
			if ( $this->destinationIntact( 'term', (int) $decision['dest'] ) ) {
				// Already imported previously (unchanged or changed): leave existing
				// term intact, mappings persist in the ledger.
				$this->note( 'term', $srcTtId, "unchanged #{$decision['dest']} (already imported, nothing to do)" );
				$this->ctx->report->record( 'term', 'unchanged' );
				return;
			}
			$this->note( 'term', $srcTtId, "restoring: destination term #{$decision['dest']} no longer exists" );
			$this->restoring = true;
		}

		// Existing destination term?
		$existing = $this->resolveExisting( 'term', $entity );
		if ( $existing ) {
			$term = $this->ctx->wp->getTermBy( $taxonomy, 'slug', isset( $entity['slug'] ) ? (string) $entity['slug'] : '' );
			if ( $term ) {
				$this->recordMaps( $srcTtId, $srcTermId, (int) $term['term_taxonomy_id'], (int) $term['term_id'], 'matched', $hash );
				$this->note( 'term', $srcTtId, "matched existing #{$term['term_id']} (by {$taxonomy}/slug)" );
				$this->ctx->report->record( 'term', $this->outcome( 'matched' ) );
				return;
			}
		}

		if ( $this->ctx->dryRun ) {
			$this->note( 'term', $srcTtId, 'would create (no existing match)' );
			$this->ctx->report->record( 'term', $this->outcome( 'created' ) );
			return;
		}

		$args = array(
			'slug'        => isset( $entity['slug'] ) ? (string) $entity['slug'] : '',
			'description' => isset( $entity['description'] ) ? (string) $entity['description'] : '',
		);
		try {
			$result = $this->ctx->wp->insertTerm( $name, $taxonomy, $this->ctx->decoder->forStorageRow( $args ) );
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
		if ( $srcTermId > 0 ) {
			$this->ctx->idMap->rememberTermId( $srcTermId, $destTermId );
		}
	}

	public function rewritePhase() {
		if ( $this->ctx->dryRun || empty( $this->writeIds ) ) {
			return;
		}
		foreach ( $this->each( 'terms' ) as $entity ) {
			$srcTtId = isset( $entity['term_taxonomy_id'] ) ? (string) $entity['term_taxonomy_id'] : '';
			if ( '' === $srcTtId || ! isset( $this->writeIds[ $srcTtId ] ) ) {
				continue;
			}
			$destTermId = $this->writeIds[ $srcTtId ];
			$taxonomy   = isset( $entity['taxonomy'] ) ? (string) $entity['taxonomy'] : '';

			$srcParent = isset( $entity['parent'] ) ? (int) $entity['parent'] : 0;
			if ( $srcParent > 0 ) {
				$destParent = $this->ctx->idMap->termId( $srcParent );
				if ( $destParent ) {
					$this->ctx->wp->updateTermParent( $destTermId, $taxonomy, $destParent );
				} else {
					$this->ctx->logger->warn( 'term', $srcTtId, "parent term_id {$srcParent} not mapped" );
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
	}
}
