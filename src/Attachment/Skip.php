<?php

namespace IdempotentImport\Attachment;

use IdempotentImport\AttachmentResult;
use IdempotentImport\Context;
use IdempotentImport\Contracts\AttachmentStrategy;

/**
 * Imports no attachments. References to them in content and _thumbnail_id are
 * left unrewritten (they will point at nothing on the destination). Use when
 * media is handled entirely out of band.
 */
class Skip implements AttachmentStrategy {

	public function import( array $attachment, Context $ctx ) {
		return AttachmentResult::skipped();
	}
}
