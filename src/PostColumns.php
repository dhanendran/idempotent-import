<?php

namespace IdempotentImport;

/**
 * Extracts the wp_posts column set for an insert from a decoded post entity.
 *
 * Derived export-only fields (meta, terms, comments, attachment_url) and
 * comment_count are dropped. post_author is resolved through the IdMap now
 * (users are imported first); post_parent is deferred to the rewrite phase and
 * set to 0 here to avoid a forward reference.
 *
 * The source ID is normally dropped and the destination reissues one. With
 * `posts.preserve_ids` it is passed through as `import_id`, which asks
 * wp_insert_post() to claim that exact ID — required wherever URLs must keep
 * resolving, since `?p={ID}` and ID-bearing references stay valid.
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

		$sourceId   = isset( $entity['ID'] ) ? (int) $entity['ID'] : 0;
		$preserving = self::preservingIds( $ctx );

		if ( $sourceId > 0 && $preserving ) {
			$cols['import_id'] = $sourceId;
		}

		// A hierarchical post's slug only has to be unique among its siblings, so the
		// parent has to be in place before wp_unique_post_slug() runs — insert with
		// post_parent = 0 and two pages that legitimately share a slug under different
		// parents get silently renamed to `-2`, changing their URL. When IDs are
		// preserved the destination parent id *is* the source one, so there is no
		// forward reference to defer (wp_insert_post does not require it to exist yet).
		$cols['post_parent'] = ( $preserving && isset( $entity['post_parent'] ) )
			? (int) $entity['post_parent']
			: 0;

		return $cols;
	}

	/**
	 * @param Context $ctx
	 * @return bool
	 */
	public static function preservingIds( Context $ctx ) {
		return (bool) $ctx->config->get( 'posts.preserve_ids', false );
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
