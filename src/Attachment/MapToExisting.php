<?php

namespace IdempotentImport\Attachment;

use IdempotentImport\AttachmentResult;
use IdempotentImport\Context;
use IdempotentImport\Contracts\AttachmentStrategy;

/**
 * Matches the exported attachment to one already present in the destination
 * media library, by original filename (basename of attachment_url). Falls back
 * to ReferenceOnly when no match is found, so nothing is silently dropped.
 */
class MapToExisting implements AttachmentStrategy {

	public function import( array $attachment, Context $ctx ) {
		$url      = isset( $attachment['attachment_url'] ) ? (string) $attachment['attachment_url'] : '';
		$filename = '' !== $url ? basename( (string) parse_url( $url, PHP_URL_PATH ) ) : '';

		if ( '' !== $filename ) {
			$existing = $ctx->wp->findAttachmentByFilename( $filename );
			if ( $existing ) {
				return new AttachmentResult( $existing, $ctx->wp->getAttachmentUrl( $existing ), 'matched' );
			}
		}

		return ( new ReferenceOnly() )->import( $attachment, $ctx );
	}
}
