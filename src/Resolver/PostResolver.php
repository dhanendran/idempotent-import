<?php

namespace IdempotentImport\Resolver;

use IdempotentImport\Context;
use IdempotentImport\Contracts\Resolver;

/**
 * Default post identity resolver. Matches by the fields in config posts.match_by
 * (default: post_type + post_name). Falls back to GUID if configured.
 *
 * Posts with an empty post_name (e.g. drafts, auto-drafts) never match and are
 * always created.
 */
class PostResolver implements Resolver {

	public function resolve( array $entity, Context $ctx ) {
		$matchBy  = (array) $ctx->config->get( 'posts.match_by', array( 'post_type', 'post_name' ) );
		$postType = isset( $entity['post_type'] ) ? (string) $entity['post_type'] : 'post';

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
}
