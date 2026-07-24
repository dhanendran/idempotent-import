<?php

namespace IdempotentImport\Mapper;

use IdempotentImport\Context;
use IdempotentImport\Contracts\MetaMapper;

/**
 * Config-driven meta mapper. Operates on the exporter's canonical meta shape
 * { key: [values] }.
 *
 * Honours the `meta.{type}` config section:
 *   rename:  { old_key: new_key }   rename (or merge into an existing key)
 *   drop:    [ key, ... ]           remove entirely
 *   numeric: [ key, ... ]           coerce numeric-string values to int
 *   refs:    { key: refType }       declares reference-bearing keys (consumed
 *                                   later by the CoreReferenceRewriter)
 *
 * Reference rewriting itself happens in phase 2; this class only reshapes keys
 * and coerces scalar value types. For type=user it also applies users.role_map
 * to the wp_capabilities meta.
 */
class DefaultMetaMapper implements MetaMapper {

	public function mapKeys( array $meta, $type, Context $ctx ) {
		$rules  = $ctx->config->metaRules( $type );
		$rename = $rules['rename'];
		$drop   = array_flip( array_map( 'strval', $rules['drop'] ) );

		$out = array();
		foreach ( $meta as $key => $values ) {
			$key = (string) $key;
			if ( isset( $drop[ $key ] ) ) {
				continue;
			}
			$newKey = isset( $rename[ $key ] ) ? (string) $rename[ $key ] : $key;
			if ( isset( $out[ $newKey ] ) ) {
				// Merge when a rename collides with an existing key.
				$out[ $newKey ] = array_merge( $out[ $newKey ], (array) $values );
			} else {
				$out[ $newKey ] = (array) $values;
			}
		}

		if ( function_exists( 'apply_filters' ) ) {
			$out = apply_filters( 'idempotent_import_meta', $out, $type, $ctx );
		}
		return is_array( $out ) ? $out : array();
	}

	public function transformValues( $key, array $values, $type, Context $ctx ) {
		$rules = $ctx->config->metaRules( $type );

		if ( in_array( $key, $rules['numeric'], true ) ) {
			$values = array_map(
				static function ( $v ) {
					return is_string( $v ) && ctype_digit( ltrim( $v, '-' ) ) ? (int) $v : $v;
				},
				$values
			);
		}

		if ( 'user' === $type && 'wp_capabilities' === $key ) {
			$roleMap = (array) $ctx->config->get( 'users.role_map', array() );
			if ( $roleMap ) {
				$values = $this->applyRoleMap( $values, $roleMap );
			}
		}

		return $values;
	}

	/**
	 * Remap role names inside wp_capabilities values.
	 *
	 * @param array $values
	 * @param array $roleMap
	 * @return array
	 */
	private function applyRoleMap( array $values, array $roleMap ) {
		foreach ( $values as $i => $caps ) {
			if ( ! is_array( $caps ) ) {
				continue;
			}
			$mapped = array();
			foreach ( $caps as $role => $enabled ) {
				$newRole            = isset( $roleMap[ $role ] ) ? $roleMap[ $role ] : $role;
				$mapped[ $newRole ] = $enabled;
			}
			$values[ $i ] = $mapped;
		}
		return $values;
	}
}
