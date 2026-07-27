<?php

namespace IdempotentImport\Resolver;

use IdempotentImport\Context;
use IdempotentImport\Contracts\Resolver;
use IdempotentImport\PostColumns;

/**
 * Default post identity resolver. Matches by the fields in config posts.match_by
 * (default: post_type + post_name). Falls back to GUID if configured.
 *
 * Posts with an empty post_name (e.g. drafts, auto-drafts) never match and are
 * always created.
 *
 * With `posts.preserve_ids` the source ID is the identity (spec 3.3.3), so the
 * destination row at that ID is considered first — but only adopted when its
 * post_type and post_name agree. An occupant that does not corroborate is left
 * unmatched, so the importer reports a collision instead of silently adopting
 * unrelated content.
 */
class PostResolver implements Resolver {

	public function resolve( array $entity, Context $ctx ) {
		$matchBy  = (array) $ctx->config->get( 'posts.match_by', array( 'post_type', 'post_name' ) );
		$postType = isset( $entity['post_type'] ) ? (string) $entity['post_type'] : 'post';

		if ( PostColumns::preservingIds( $ctx ) ) {
			return $this->resolveById( $entity, $postType, $ctx );
		}

		if ( in_array( 'guid', $matchBy, true ) && ! empty( $entity['guid'] ) ) {
			$id = $ctx->wp->getPostIdBy( 'guid', (string) $entity['guid'], $postType );
			if ( $id ) {
				return $id;
			}
		}

		if ( in_array( 'post_name', $matchBy, true ) ) {
			$name = isset( $entity['post_name'] ) ? (string) $entity['post_name'] : '';
			if ( '' === $name ) {
				return null;
			}
			$id = $ctx->wp->getPostIdBy( 'name', $name, $postType );
			if ( $id ) {
				return $id;
			}
		}
		return null;
	}

	/**
	 * Adopt the row already sitting at the source ID when it is demonstrably the
	 * same post — which makes a re-import after ledger loss self-healing.
	 *
	 * @param array   $entity
	 * @param string  $postType
	 * @param Context $ctx
	 * @return int|null
	 */
	private function resolveById( array $entity, $postType, Context $ctx ) {
		$sourceId = isset( $entity['ID'] ) ? (int) $entity['ID'] : 0;
		if ( $sourceId <= 0 ) {
			return null;
		}
		$existing = $ctx->wp->getPost( $sourceId );
		if ( ! $existing ) {
			return null;
		}
		$sameType = isset( $existing['post_type'] ) && (string) $existing['post_type'] === $postType;
		$sameName = isset( $existing['post_name'] )
			&& (string) $existing['post_name'] === (string) ( isset( $entity['post_name'] ) ? $entity['post_name'] : '' );

		return ( $sameType && $sameName ) ? $sourceId : null;
	}
}
