<?php

namespace IdempotentImport;

/**
 * The outcome of mapping a source user, returned by a UserMapper.
 *
 * - REUSE:  merge into an existing destination user id.
 * - CREATE: create a new destination user from the (possibly modified) data.
 * - REMAP:  do not create; treat this source user as an existing destination id
 *           (e.g. collapse many source authors to one editorial account).
 * - SKIP:   do not import; references to this user fall back to default_author.
 */
class UserDecision {

	const REUSE  = 'reuse';
	const CREATE = 'create';
	const REMAP  = 'remap';
	const SKIP   = 'skip';

	/** @var string */
	public $action;

	/** @var int|null Destination user id for REUSE / REMAP. */
	public $destId;

	/** @var array Modified user data for CREATE. */
	public $data;

	/**
	 * @param string   $action
	 * @param int|null $destId
	 * @param array    $data
	 */
	public function __construct( $action, $destId = null, array $data = array() ) {
		$this->action = $action;
		$this->destId = null === $destId ? null : (int) $destId;
		$this->data   = $data;
	}

	public static function reuse( $destId ) {
		return new self( self::REUSE, $destId );
	}

	public static function create( array $data ) {
		return new self( self::CREATE, null, $data );
	}

	public static function remap( $destId ) {
		return new self( self::REMAP, $destId );
	}

	public static function skip() {
		return new self( self::SKIP );
	}
}
