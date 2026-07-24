<?php

namespace IdempotentImport\Contracts;

use IdempotentImport\AttachmentResult;
use IdempotentImport\Context;

/**
 * Decides how an exported attachment (which carries only a URL, never binary
 * data) becomes a destination attachment.
 *
 * Built-in strategies: Sideload (download into the media library — the
 * default), ReferenceOnly (recreate the post, keep the external URL),
 * MapToExisting (match an attachment already present), Skip.
 */
interface AttachmentStrategy {

	/**
	 * @param array   $attachment Decoded attachment entity (a post with attachment_url).
	 * @param Context $ctx
	 * @return AttachmentResult
	 */
	public function import( array $attachment, Context $ctx );
}
