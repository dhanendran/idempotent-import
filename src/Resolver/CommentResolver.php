<?php

namespace IdempotentImport\Resolver;

use IdempotentImport\Context;
use IdempotentImport\Contracts\Resolver;

/**
 * Default comment identity resolver. A comment has no natural unique key, so we
 * match on (destination post, author email, date_gmt) — enough to avoid
 * duplicating the same comment on re-import. Requires the parent post to have
 * been mapped already.
 */
class CommentResolver implements Resolver {

	public function resolve( array $entity, Context $ctx ) {
		$sourcePost = isset( $entity['comment_post_ID'] ) ? (int) $entity['comment_post_ID'] : 0;
		$destPost   = $ctx->idMap->post( $sourcePost );
		if ( ! $destPost ) {
			return null;
		}
		$criteria = array( 'comment_post_ID' => $destPost );
		if ( ! empty( $entity['comment_author_email'] ) ) {
			$criteria['comment_author_email'] = (string) $entity['comment_author_email'];
		}
		if ( ! empty( $entity['comment_date_gmt'] ) ) {
			$criteria['comment_date_gmt'] = (string) $entity['comment_date_gmt'];
		}
		return $ctx->wp->findCommentId( $criteria );
	}
}
