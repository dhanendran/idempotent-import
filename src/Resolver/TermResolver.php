<?php

namespace IdempotentImport\Resolver;

use IdempotentImport\Context;
use IdempotentImport\Contracts\Resolver;

/**
 * Default term identity resolver. Matches by (taxonomy, slug) — the natural
 * unique key for a term within a taxonomy. Returns the existing destination
 * term_taxonomy_id.
 */
class TermResolver implements Resolver {

	public function resolve( array $entity, Context $ctx ) {
		$taxonomy = isset( $entity['taxonomy'] ) ? (string) $entity['taxonomy'] : '';
		$slug     = isset( $entity['slug'] ) ? (string) $entity['slug'] : '';
		if ( '' === $taxonomy || '' === $slug ) {
			return null;
		}
		$term = $ctx->wp->getTermBy( $taxonomy, 'slug', $slug );
		if ( $term ) {
			// Record the term_id shortcuts so the create phase can reuse them.
			return (int) $term['term_taxonomy_id'];
		}
		return null;
	}
}
