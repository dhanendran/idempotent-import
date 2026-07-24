<?php

namespace IdempotentImport\Attachment;

use IdempotentImport\AttachmentResult;
use IdempotentImport\Context;
use IdempotentImport\Contracts\AttachmentStrategy;

/**
 * Default strategy. Downloads the exported attachment_url into the destination
 * media library (media_handle_sideload creates the attachment post, stores the
 * file, and regenerates _wp_attachment_metadata), then maps the source URL to
 * the freshly-generated destination URL for content rewriting.
 */
class Sideload implements AttachmentStrategy {

	public function import( array $attachment, Context $ctx ) {
		$url = isset( $attachment['attachment_url'] ) ? (string) $attachment['attachment_url'] : '';
		if ( '' === $url ) {
			return AttachmentResult::skipped();
		}

		$destId  = $ctx->wp->sideloadMedia( $url, 0 );
		$destUrl = $ctx->wp->getAttachmentUrl( $destId );

		return new AttachmentResult( $destId, $destUrl, 'created' );
	}
}
