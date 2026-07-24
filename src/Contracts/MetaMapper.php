<?php

namespace IdempotentImport\Contracts;

use IdempotentImport\Context;

/**
 * Transforms an entity's meta before it is written.
 *
 * Operates on the exporter's canonical meta shape: { key: [value, value, ...] }.
 * This is the first-class home for meta-key remapping (old_key -> new_key),
 * dropping keys, coercing value types, and declaring which keys hold references.
 *
 * The default implementation is config-driven (the `meta.{type}` section:
 * rename, drop, numeric, refs).
 */
interface MetaMapper {

	/**
	 * Rename, drop or merge meta keys.
	 *
	 * @param array   $meta { key: [values] }
	 * @param string  $type post|term|user|comment
	 * @param Context $ctx
	 * @return array Transformed { key: [values] }.
	 */
	public function mapKeys( array $meta, $type, Context $ctx );

	/**
	 * Transform the values under a single key (type coercion, splitting, etc.).
	 * Reference rewriting happens later, in the rewrite phase.
	 *
	 * @param string  $key
	 * @param array   $values
	 * @param string  $type
	 * @param Context $ctx
	 * @return array
	 */
	public function transformValues( $key, array $values, $type, Context $ctx );
}
