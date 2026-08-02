<?php

namespace IdempotentImport\Attachment;

use IdempotentImport\AttachmentResult;
use IdempotentImport\Context;
use IdempotentImport\Contracts\AttachmentStrategy;
use IdempotentImport\PostColumns;

/**
 * Recreates the attachment as a post but does not download the binary. The
 * destination keeps pointing at the original (often CDN) URL. Useful when media
 * already lives on shared storage and only the WordPress records need to exist.
 */
class ReferenceOnly implements AttachmentStrategy {

	public function import( array $attachment, Context $ctx ) {
		$url  = isset( $attachment['attachment_url'] ) ? (string) $attachment['attachment_url'] : '';
		$cols = PostColumns::fromEntity( $attachment, $ctx );
		if ( '' !== $url ) {
			$cols['guid'] = $url;
		}

		$destId = $ctx->wp->insertPost( $ctx->decoder->forStorageRow( $cols ) );

		// Preserve the original relative file path if the source recorded one.
		$meta = isset( $attachment['meta'] ) && is_array( $attachment['meta'] ) ? $attachment['meta'] : array();
		if ( isset( $meta['_wp_attached_file'][0] ) ) {
			$ctx->wp->updatePostMeta(
				$destId,
				'_wp_attached_file',
				$ctx->decoder->forStorageValue( $meta['_wp_attached_file'][0] )
			);
		}

		// Map to the URL the destination serves, not the source one, or content rewriting is a no-op.
		$destUrl = $ctx->wp->getAttachmentUrl( $destId );

		return new AttachmentResult( $destId, $destUrl ? $destUrl : ( '' !== $url ? $url : null ), 'referenced' );
	}
}
