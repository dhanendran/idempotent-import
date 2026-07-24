<?php

namespace IdempotentImport\Mapper;

use IdempotentImport\Context;
use IdempotentImport\Contracts\UserMapper;
use IdempotentImport\UserDecision;

/**
 * Config-driven user mapper.
 *
 * Honours the `users` config section:
 *   remap:      { sourceUserId: destUserId }  collapse/redirect specific authors
 *   on_missing: create | skip | assign_default   policy for un-remapped users
 *   default_author: fallback destination id for assign_default
 *
 * Emits the `idempotent_import_map_user` filter (when WordPress is loaded) so
 * projects can override per-user without writing a full mapper class.
 */
class DefaultUserMapper implements UserMapper {

	/** wp_users columns we hand to wp_insert_user; everything else is meta. */
	const USER_COLUMNS = array(
		'user_login',
		'user_nicename',
		'user_email',
		'user_url',
		'user_registered',
		'user_status',
		'display_name',
	);

	public function map( array $sourceUser, Context $ctx ) {
		$decision = $this->decide( $sourceUser, $ctx );

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'idempotent_import_map_user', $decision, $sourceUser, $ctx );
			if ( $filtered instanceof UserDecision ) {
				$decision = $filtered;
			}
		}
		return $decision;
	}

	/**
	 * @param array   $sourceUser
	 * @param Context $ctx
	 * @return UserDecision
	 */
	private function decide( array $sourceUser, Context $ctx ) {
		$srcId = isset( $sourceUser['ID'] ) ? (string) $sourceUser['ID'] : '';
		$remap = (array) $ctx->config->get( 'users.remap', array() );

		if ( '' !== $srcId && array_key_exists( $srcId, $remap ) ) {
			return UserDecision::remap( (int) $remap[ $srcId ] );
		}
		if ( '' !== $srcId && array_key_exists( (int) $srcId, $remap ) ) {
			return UserDecision::remap( (int) $remap[ (int) $srcId ] );
		}

		$onMissing = (string) $ctx->config->get( 'users.on_missing', 'create' );
		if ( 'skip' === $onMissing ) {
			return UserDecision::skip();
		}
		if ( 'assign_default' === $onMissing ) {
			return UserDecision::remap( (int) $ctx->config->get( 'users.default_author', 1 ) );
		}

		return UserDecision::create( $this->columns( $sourceUser ) );
	}

	/**
	 * Reduce a source user row to the columns wp_insert_user accepts.
	 *
	 * @param array $sourceUser
	 * @return array
	 */
	private function columns( array $sourceUser ) {
		$out = array();
		foreach ( self::USER_COLUMNS as $col ) {
			if ( array_key_exists( $col, $sourceUser ) ) {
				$out[ $col ] = $sourceUser[ $col ];
			}
		}
		return $out;
	}
}
