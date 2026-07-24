<?php

namespace IdempotentImport\Contracts;

use IdempotentImport\Context;
use IdempotentImport\UserDecision;

/**
 * Maps a source user onto a destination outcome (reuse / create / remap / skip).
 *
 * This is the first-class home for author remapping: collapsing several source
 * authors into one, remapping by email domain, or role changes. The default
 * implementation honours the `users` section of the import-map config.
 */
interface UserMapper {

	/**
	 * @param array   $sourceUser Decoded user entity.
	 * @param Context $ctx
	 * @return UserDecision
	 */
	public function map( array $sourceUser, Context $ctx );
}
