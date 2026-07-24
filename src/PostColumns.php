<?php

namespace IdempotentImport;

/**
 * Extracts the wp_posts column set for an insert from a decoded post entity.
 *
 * Derived export-only fields (meta, terms, comments, attachment_url), the
 * source ID, and comment_count are dropped. post_author is resolved through the
 * IdMap now (users are imported first); post_parent is deferred to the rewrite
 * phase and set to 0 here to avoid a forward reference.
 */
class PostColumns {

	/** Columns copied verbatim from the source row. */
	const COLUMNS = array(
		'post_date',
		'post_date_gmt',
		'post_content',
		'post_title',
		'post_excerpt',
		'post_status',
		'comment_status',
		'ping_status',
		'post_password',
		'post_name',
		'to_ping',
		'pinged',
		'post_modified',
		'post_modified_gmt',
		'post_content_filtered',
		'guid',
		'menu_order',
		'post_type',
		'post_mime_type',
	);

	/**
	 * @param array   $entity
	 * @param Context $ctx
	 * @return array Column => value, ready for the Decoder + insertPost.
	 */
	public static function fromEntity( array $entity, Context $ctx ) {
		$cols = array();
		foreach ( self::COLUMNS as $c ) {
			if ( array_key_exists( $c, $entity ) ) {
				$cols[ $c ] = $entity[ $c ];
			}
		}

		$sourceAuthor      = isset( $entity['post_author'] ) ? (int) $entity['post_author'] : 0;
		$cols['post_author'] = self::resolveAuthor( $sourceAuthor, $ctx );
		$cols['post_parent'] = 0; // Deferred to the rewrite phase.

		return $cols;
	}

	/**
	 * @param int     $sourceAuthor
	 * @param Context $ctx
	 * @return int
	 */
	public static function resolveAuthor( $sourceAuthor, Context $ctx ) {
		$mapped = $ctx->idMap->user( $sourceAuthor );
		if ( $mapped ) {
			return $mapped;
		}
		return (int) $ctx->config->get( 'users.default_author', 1 );
	}
}
