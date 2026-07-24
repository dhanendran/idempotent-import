<?php

namespace IdempotentImport\Resolver;

use IdempotentImport\Context;
use IdempotentImport\Contracts\Resolver;

/**
 * Default user identity resolver. Matches an incoming source user to an existing
 * destination user by the fields listed in config users.match_by
 * (default: user_login then user_email).
 */
class UserResolver implements Resolver {

	public function resolve( array $entity, Context $ctx ) {
		$matchBy = $ctx->config->get( 'users.match_by', array( 'user_login', 'user_email' ) );

		foreach ( (array) $matchBy as $field ) {
			$value = isset( $entity[ $field ] ) ? (string) $entity[ $field ] : '';
			if ( '' === $value ) {
				continue;
			}
			$lookup = 'user_email' === $field ? 'email' : ( 'user_nicename' === $field ? 'slug' : 'login' );
			$id     = $ctx->wp->getUserIdBy( $lookup, $value );
			if ( $id ) {
				return $id;
			}
		}
		return null;
	}
}
