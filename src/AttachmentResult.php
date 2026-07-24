<?php

namespace IdempotentImport;

/**
 * Result of an attachment strategy: the destination attachment id (if one was
 * created or matched) and the destination URL (for content URL rewriting).
 */
class AttachmentResult {

	/** @var int|null */
	public $destId;

	/** @var string|null */
	public $destUrl;

	/** @var string created|matched|referenced|skipped */
	public $outcome;

	/**
	 * @param int|null    $destId
	 * @param string|null $destUrl
	 * @param string      $outcome
	 */
	public function __construct( $destId, $destUrl, $outcome ) {
		$this->destId  = null === $destId ? null : (int) $destId;
		$this->destUrl = $destUrl;
		$this->outcome = $outcome;
	}

	public static function skipped() {
		return new self( null, null, 'skipped' );
	}
}
