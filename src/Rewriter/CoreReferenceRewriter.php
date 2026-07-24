<?php

namespace IdempotentImport\Rewriter;

use IdempotentImport\Context;
use IdempotentImport\Contracts\ReferenceRewriter;

/**
 * The default reference rewriter for meta values. Rewrites source ids to
 * destination ids for:
 *
 *   - implicitly known keys (e.g. _thumbnail_id -> attachment id)
 *   - keys declared in config as meta.{type}.refs = { key: refType }
 *
 * Supported refTypes: post, post[], attachment, attachment[], term, term[],
 * user, user[]. A "[]" suffix means the value is a list of ids.
 *
 * Values whose id has no mapping are left unchanged (the caller may warn). This
 * rewriter deliberately handles only the common scalar / list-of-ids shapes;
 * anything more structured belongs in a project-specific ReferenceRewriter.
 */
class CoreReferenceRewriter implements ReferenceRewriter {

	/** Keys that are always references, regardless of config. */
	const IMPLICIT = array(
		'_thumbnail_id' => 'attachment',
	);

	public function handles( $context, Context $ctx ) {
		return null !== $this->refTypeFor( $context, $ctx );
	}

	public function rewrite( $value, $context, Context $ctx ) {
		$refType = $this->refTypeFor( $context, $ctx );
		if ( null === $refType ) {
			return $value;
		}

		$isList = '[]' === substr( $refType, -2 );
		$base   = $isList ? substr( $refType, 0, -2 ) : $refType;

		if ( $isList || is_array( $value ) ) {
			$out = array();
			foreach ( (array) $value as $k => $v ) {
				$out[ $k ] = $this->mapScalar( $v, $base, $ctx );
			}
			return $out;
		}
		return $this->mapScalar( $value, $base, $ctx );
	}

	/**
	 * @param string  $context e.g. "post.meta._thumbnail_id"
	 * @param Context $ctx
	 * @return string|null
	 */
	private function refTypeFor( $context, Context $ctx ) {
		$parts = explode( '.', $context );
		if ( count( $parts ) < 3 || 'meta' !== $parts[1] ) {
			return null;
		}
		$type = $parts[0];
		$key  = implode( '.', array_slice( $parts, 2 ) );

		if ( isset( self::IMPLICIT[ $key ] ) ) {
			return self::IMPLICIT[ $key ];
		}
		$refs = $ctx->config->metaRules( $type )['refs'];
		return isset( $refs[ $key ] ) ? (string) $refs[ $key ] : null;
	}

	/**
	 * @param mixed   $value
	 * @param string  $base post|attachment|term|user
	 * @param Context $ctx
	 * @return mixed
	 */
	private function mapScalar( $value, $base, Context $ctx ) {
		if ( ! is_scalar( $value ) ) {
			return $value;
		}
		$sourceId = (string) $value;
		if ( ! ctype_digit( ltrim( $sourceId, '-' ) ) ) {
			return $value;
		}

		switch ( $base ) {
			case 'attachment':
			case 'post':
				$dest = $ctx->idMap->post( (int) $sourceId );
				break;
			case 'term':
				$dest = $ctx->idMap->termId( (int) $sourceId );
				break;
			case 'user':
				$dest = $ctx->idMap->user( (int) $sourceId );
				break;
			default:
				$dest = null;
		}

		if ( null === $dest ) {
			return $value; // No mapping; leave as-is.
		}
		// Preserve the original scalar type (postmeta ids are stored as strings).
		return is_int( $value ) ? (int) $dest : (string) $dest;
	}
}
