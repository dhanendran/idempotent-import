<?php

namespace IdempotentImport\Attachment;

use IdempotentImport\AttachmentResult;
use IdempotentImport\Context;
use IdempotentImport\Contracts\AttachmentStrategy;
use IdempotentImport\PostColumns;

/**
 * Recreates the attachment as a post but does not download the binary, keeping
 * the source's relative file path. Use it when the files travel by another route
 * — a folder copy, the VIP Files API, shared storage — and only the WordPress
 * records need creating. It is also the only strategy compatible with
 * --preserve-ids, since a sideload cannot land on its source id.
 */
class ReferenceOnly implements AttachmentStrategy {

	public function import( array $attachment, Context $ctx ) {
		$url  = isset( $attachment['attachment_url'] ) ? (string) $attachment['attachment_url'] : '';
		$cols = PostColumns::fromEntity( $attachment, $ctx );

		$meta = isset( $attachment['meta'] ) && is_array( $attachment['meta'] ) ? $attachment['meta'] : array();
		$file = isset( $meta['_wp_attached_file'][0] ) ? (string) $meta['_wp_attached_file'][0] : '';

		// The URL the destination will serve this file from once it lands in uploads.
		// Everything WordPress renders itself resolves the preserved path against the
		// destination's own base, so this — not the source URL — is what the record
		// and the content have to say (spec 3.3.7, which rewrites guid alongside the
		// in-content URLs). Keeping the source URL would leave the same image named
		// from two places, and the old one 404s once that instance is retired.
		//
		// guid has to be decided here: wp_update_post() never writes the column, so
		// it cannot be corrected after the insert.
		$destUrl = $this->destUrl( $file, $ctx );
		if ( null !== $destUrl ) {
			$cols['guid'] = $destUrl;
		} elseif ( '' !== $url ) {
			$cols['guid'] = $url;
		}

		$destId = $ctx->wp->insertPost( $ctx->decoder->forStorageRow( $cols ) );

		// Preserve the original relative file path if the source recorded one.
		if ( '' !== $file ) {
			$ctx->wp->updatePostMeta( $destId, '_wp_attached_file', $ctx->decoder->forStorageValue( $file ) );
		}

		if ( null === $destUrl ) {
			// Nothing the uploads base could prefix: let WordPress resolve it, which
			// falls back to the guid we just stored.
			$destUrl = $ctx->wp->getAttachmentUrl( $destId );
		}

		return new AttachmentResult( $destId, $destUrl ? $destUrl : ( '' !== $url ? $url : null ), 'referenced' );
	}

	/**
	 * Where the destination serves a preserved relative path from.
	 *
	 * @param string  $file The snapshot's _wp_attached_file value.
	 * @param Context $ctx
	 * @return string|null Null when the path is absolute or already a URL, so the
	 *                     uploads base cannot stand in front of it.
	 */
	private function destUrl( $file, Context $ctx ) {
		if ( '' === $file || '/' === $file[0] || false !== strpos( $file, '://' ) ) {
			return null;
		}
		$base = rtrim( (string) $ctx->wp->uploadsBaseUrl(), '/' );
		return '' === $base ? null : $base . '/' . $file;
	}
}
