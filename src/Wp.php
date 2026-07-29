<?php

namespace IdempotentImport;

use IdempotentImport\Contracts\Ledger;
use IdempotentImport\Contracts\WordPress;

/**
 * Production WordPress gateway. Thin delegations to WP core functions.
 *
 * WP_Error returns are converted to RuntimeExceptions so the importers can
 * treat any failure uniformly as a per-entity skip.
 */
class Wp implements WordPress {

	/* ---- Destination reconciliation -------------------------------------- */

	public function missingDestIds( $type, Ledger $ledger ) {
		global $wpdb;
		$identity = $ledger->sqlIdentity();
		$tables   = array(
			'user'    => array( $wpdb->users, 'ID' ),
			'post'    => array( $wpdb->posts, 'ID' ),
			'term'    => array( $wpdb->term_taxonomy, 'term_taxonomy_id' ),
			'comment' => array( $wpdb->comments, 'comment_ID' ),
		);
		if ( null === $identity || ! isset( $tables[ $type ] ) ) {
			return array(); // Nothing queryable to reconcile against: assume intact.
		}
		list( $table, $column ) = $tables[ $type ];

		// dest_id is VARCHAR; cast it so the destination side stays a PK lookup.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT l.dest_id FROM {$identity['table']} l
				 LEFT JOIN {$table} d ON d.{$column} = CAST(l.dest_id AS UNSIGNED)
				 WHERE l.source_key = %s AND l.entity_type = %s AND d.{$column} IS NULL
				 LIMIT %d",
				$identity['source_key'],
				$type,
				self::MISSING_LIMIT + 1
			)
		);
		return array_map( 'intval', (array) $rows );
	}

	public function nonMemberUserIds( Ledger $ledger ) {
		global $wpdb;
		$identity = $ledger->sqlIdentity();
		if ( null === $identity ) {
			return array();
		}
		// Membership is the presence of this blog's capabilities key — the same
		// key userMetaKey() rebases role meta onto when the importer grants it.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT l.dest_id FROM {$identity['table']} l
				 LEFT JOIN {$wpdb->usermeta} m
				   ON m.user_id = CAST(l.dest_id AS UNSIGNED) AND m.meta_key = %s
				 WHERE l.source_key = %s AND l.entity_type = 'user' AND m.umeta_id IS NULL
				 LIMIT %d",
				$wpdb->get_blog_prefix() . 'capabilities',
				$identity['source_key'],
				self::MISSING_LIMIT + 1
			)
		);
		return array_map( 'intval', (array) $rows );
	}

	/* ---- Users ----------------------------------------------------------- */

	public function getUserIdBy( $field, $value ) {
		$user = get_user_by( $field, $value );
		return $user ? (int) $user->ID : null;
	}

	public function insertUser( array $data ) {
		if ( ! isset( $data['user_pass'] ) ) {
			$data['user_pass'] = wp_generate_password( 32, true, true );
		}
		$id = wp_insert_user( $data );
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( 'wp_insert_user: ' . $id->get_error_message() );
		}
		return (int) $id;
	}

	public function addUserMeta( $userId, $key, $value ) {
		$metaId = add_user_meta( (int) $userId, $this->userMetaKey( $key ), $value );
		$this->keepSerializedStringVerbatim( $GLOBALS['wpdb']->usermeta, 'umeta_id', $metaId, $value, (int) $userId, 'user_meta' );
	}

	public function deleteUserMeta( $userId, $key ) {
		delete_user_meta( (int) $userId, $this->userMetaKey( $key ) );
	}

	/**
	 * Rebase the canonical role keys onto this blog's table prefix so imported
	 * capabilities apply to the destination subsite (blog N uses wp_N_capabilities).
	 *
	 * Anchored to the literal `wp_` the exporter canonicalises to, plus the standard
	 * `wp_N_` multisite form. An open prefix class would also catch unrelated plugin
	 * meta — `wpseo_capabilities` and the like — and collapse it onto the role key,
	 * losing the plugin's data and the real role with it.
	 *
	 * A source site with a custom table prefix is the exporter's job to canonicalise;
	 * guessing here is what caused that collision.
	 *
	 * @param string $key
	 * @return string
	 */
	protected function userMetaKey( $key ) {
		if ( preg_match( '/^wp_(?:\d+_)?(capabilities|user_level)$/', (string) $key, $m ) ) {
			global $wpdb;
			return $wpdb->get_blog_prefix() . $m[1];
		}
		return $key;
	}

	/* ---- Terms ----------------------------------------------------------- */

	public function getTermBy( $taxonomy, $field, $value ) {
		$term = get_term_by( $field, $value, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}
		return array(
			'term_id'          => (int) $term->term_id,
			'term_taxonomy_id' => (int) $term->term_taxonomy_id,
		);
	}

	public function insertTerm( $name, $taxonomy, array $args ) {
		$result = wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			// term_exists: reuse.
			$existing = $result->get_error_data();
			if ( is_array( $existing ) && isset( $existing['term_id'] ) ) {
				$term = get_term( (int) $existing['term_id'], $taxonomy );
				if ( $term && ! is_wp_error( $term ) ) {
					return array(
						'term_id'          => (int) $term->term_id,
						'term_taxonomy_id' => (int) $term->term_taxonomy_id,
					);
				}
			}
			throw new \RuntimeException( 'wp_insert_term: ' . $result->get_error_message() );
		}
		return array(
			'term_id'          => (int) $result['term_id'],
			'term_taxonomy_id' => (int) $result['term_taxonomy_id'],
		);
	}

	public function insertTermWithIds( $termId, $ttId, $name, $taxonomy, array $args ) {
		global $wpdb;
		$termId = (int) $termId;
		$ttId   = (int) $ttId;

		// Written with $wpdb rather than wp_insert_term() because there is no
		// import_id equivalent for terms, and a reissued term_id breaks every
		// reference the snapshot carries. Values arrive slashed (the Decoder mirrors
		// what core's own APIs expect), so unslash before a direct write — $wpdb
		// stores verbatim, where wp_insert_term would have unslashed for us.
		$slug = wp_unslash( (string) ( isset( $args['slug'] ) ? $args['slug'] : '' ) );
		if ( '' === $slug ) {
			$slug = sanitize_title( wp_unslash( (string) $name ) );
		}

		// A term_id of 0 asks for a fresh one: the caller is splitting a source term
		// whose term_id is already taken (see Terms::insert()).
		$row = array(
			'name'       => wp_unslash( (string) $name ),
			'slug'       => $slug,
			'term_group' => (int) ( isset( $args['term_group'] ) ? $args['term_group'] : 0 ),
		);
		if ( $termId > 0 ) {
			$row['term_id'] = $termId;
		}
		$ok = $wpdb->insert( $wpdb->terms, $row );
		if ( ! $ok ) {
			throw new \RuntimeException( "could not insert wp_terms row at term_id {$termId}: " . $wpdb->last_error );
		}
		if ( $termId <= 0 ) {
			$termId = (int) $wpdb->insert_id;
		}

		$ok = $wpdb->insert(
			$wpdb->term_taxonomy,
			array(
				'term_taxonomy_id' => $ttId,
				'term_id'          => $termId,
				'taxonomy'         => wp_unslash( (string) $taxonomy ),
				'description'      => wp_unslash( (string) ( isset( $args['description'] ) ? $args['description'] : '' ) ),
				'parent'           => (int) ( isset( $args['parent'] ) ? $args['parent'] : 0 ),
				'count'            => (int) ( isset( $args['count'] ) ? $args['count'] : 0 ),
			)
		);
		if ( ! $ok ) {
			throw new \RuntimeException( "could not insert wp_term_taxonomy row at term_taxonomy_id {$ttId}: " . $wpdb->last_error );
		}

		clean_term_cache( array( $termId ), (string) $taxonomy );

		return array(
			'term_id'          => $termId,
			'term_taxonomy_id' => $ttId,
		);
	}

	public function taxonomyExists( $taxonomy ) {
		return taxonomy_exists( (string) $taxonomy );
	}

	public function getTermRow( $termId ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT term_id, name, slug, term_group FROM {$wpdb->terms} WHERE term_id = %d", (int) $termId ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	public function getTermTaxonomyRow( $ttId ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT term_taxonomy_id, term_id, taxonomy, parent FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d",
				(int) $ttId
			),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	public function addTermMeta( $termId, $key, $value ) {
		$metaId = add_term_meta( (int) $termId, $key, $value );
		$this->keepSerializedStringVerbatim( $GLOBALS['wpdb']->termmeta, 'meta_id', $metaId, $value, (int) $termId, 'term_meta' );
	}

	public function deleteTermMeta( $termId, $key ) {
		delete_term_meta( (int) $termId, $key );
	}

	public function updateTermFields( $termId, $taxonomy, array $fields ) {
		$termId = (int) $termId;

		// term_group is not a wp_update_term() argument (it only sets it as a side
		// effect of alias_of), so it takes a direct write.
		if ( array_key_exists( 'term_group', $fields ) ) {
			global $wpdb;
			$wpdb->update( $wpdb->terms, array( 'term_group' => (int) $fields['term_group'] ), array( 'term_id' => $termId ) );
			unset( $fields['term_group'] );
		}

		if ( empty( $fields ) ) {
			clean_term_cache( array( $termId ), (string) $taxonomy );
			return;
		}

		$result = wp_update_term( $termId, (string) $taxonomy, $fields );
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( 'wp_update_term: ' . $result->get_error_message() );
		}
	}

	public function setTermsAutoIncrement( $nextTermId, $nextTtId ) {
		global $wpdb;
		$this->raiseAutoIncrement( $wpdb->terms, 'term_id', (int) $nextTermId );
		$this->raiseAutoIncrement( $wpdb->term_taxonomy, 'term_taxonomy_id', (int) $nextTtId );
	}

	/**
	 * Push a table's AUTO_INCREMENT to at least $nextId, never below its own max.
	 *
	 * @param string $table    Core-derived table name.
	 * @param string $column   Its auto-increment primary key.
	 * @param int    $nextId
	 * @return void
	 */
	private function raiseAutoIncrement( $table, $column, $nextId ) {
		global $wpdb;
		$highest = (int) $wpdb->get_var( "SELECT COALESCE( MAX( {$column} ), 0 ) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table and column are core-derived, not input.
		$target  = max( $nextId, $highest + 1 );
		if ( $target < 1 ) {
			return;
		}
		$wpdb->query( "ALTER TABLE {$table} AUTO_INCREMENT = {$target}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name cannot be parameterised; it is core-derived and the value is cast to int.
	}

	/* ---- Posts ----------------------------------------------------------- */

	public function getPostIdBy( $field, $value, $postType ) {
		if ( 'guid' === $field ) {
			global $wpdb;
			$id = $wpdb->get_var(
				$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE guid = %s LIMIT 1", $value )
			);
			return $id ? (int) $id : null;
		}
		// name (post_name / slug).
		$page = get_page_by_path( $value, OBJECT, $postType );
		if ( $page ) {
			return (int) $page->ID;
		}
		global $wpdb;
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s LIMIT 1",
				$value,
				$postType
			)
		);
		return $id ? (int) $id : null;
	}

	public function getPost( $postId ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", (int) $postId ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	public function insertPost( array $data ) {
		$id = $this->withPreservedColumns(
			$data,
			static function () use ( $data ) {
				return wp_insert_post( $data, true );
			}
		);
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( 'wp_insert_post: ' . $id->get_error_message() );
		}
		return (int) $id;
	}

	/**
	 * Pin the columns WordPress would otherwise derive (see
	 * WordPress::PRESERVED_COLUMNS) to the values the caller supplied.
	 *
	 * The filters run after core has finished deriving but before it unslashes and
	 * writes, so the values handed back must be slashed — which the Decoder has
	 * already done for everything reaching this class.
	 *
	 * @param array    $columns Slashed column => value, as passed to the core call.
	 * @param callable $work
	 * @return mixed Whatever $work returns.
	 */
	private function withPreservedColumns( array $columns, callable $work ) {
		$pinned = array_intersect_key( $columns, array_flip( self::PRESERVED_COLUMNS ) );
		if ( empty( $pinned ) || ! function_exists( 'add_filter' ) ) {
			return $work();
		}

		// Only override keys core actually built, so this can never introduce a column.
		$data_guard = static function ( $data ) use ( $pinned ) {
			return array_merge( $data, array_intersect_key( $pinned, $data ) );
		};

		$guards = array(
			'wp_insert_post_data'       => $data_guard,
			'wp_insert_attachment_data' => $data_guard,
		);

		if ( array_key_exists( 'post_name', $pinned ) ) {
			// The slug needs its own guards: filtering $data is not enough, because core
			// reaches the column by two other routes. wp_unique_post_slug() renames a slug
			// another post already holds, and after the insert core fills an empty
			// post_name in with a direct UPDATE that no data filter sees. Answering with
			// the source's slug — empty string included — settles both.
			//
			// The value has to be unslashed here: that late UPDATE writes what it is given,
			// unlike the insert path, which unslashes after the data filter.
			$slug = function_exists( 'wp_unslash' ) ? wp_unslash( (string) $pinned['post_name'] ) : (string) $pinned['post_name'];

			$guards['pre_wp_unique_post_slug'] = static function () use ( $slug ) {
				return $slug;
			};

			// Importing a live post whose slug a trashed post already holds makes core
			// rename that trashed post to `slug__trashed` behind our back — corrupting a
			// row this run already imported correctly.
			$guards['add_trashed_suffix_to_trashed_posts'] = '__return_false';
		}

		foreach ( $guards as $hook => $callback ) {
			add_filter( $hook, $callback, PHP_INT_MAX );
		}
		try {
			return $work();
		} finally {
			foreach ( $guards as $hook => $callback ) {
				remove_filter( $hook, $callback, PHP_INT_MAX );
			}
		}
	}

	public function setPostsAutoIncrement( $nextId ) {
		global $wpdb;
		$this->raiseAutoIncrement( $wpdb->posts, 'ID', (int) $nextId );
	}

	public function updatePostFields( $postId, array $fields ) {
		$fields['ID'] = (int) $postId;
		$result       = $this->withPreservedColumns(
			$fields,
			static function () use ( $fields ) {
				return wp_update_post( $fields, true );
			}
		);
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( 'wp_update_post: ' . $result->get_error_message() );
		}
	}

	public function addPostMeta( $postId, $key, $value ) {
		$metaId = add_post_meta( (int) $postId, $key, $value );
		$this->keepSerializedStringVerbatim( $GLOBALS['wpdb']->postmeta, 'meta_id', $metaId, $value, (int) $postId, 'post_meta' );
	}

	/**
	 * Put back a value that only *looks* serialized.
	 *
	 * maybe_serialize() re-serializes any string is_serialized() recognises, so a
	 * meta value stored on the source as `b:0;` lands as `s:4:"b:0;";` — a different
	 * value, which then reads back as the string "b:0;" instead of false. The meta
	 * API offers no way to opt out, so correct the column once the row exists.
	 *
	 * @param string     $table
	 * @param string     $idColumn
	 * @param int|false  $metaId    Row id returned by the add_*_meta call.
	 * @param mixed      $value     The slashed value that was handed to it.
	 * @param int        $objectId  For cache invalidation.
	 * @param string     $cacheGroup
	 * @return void
	 */
	private function keepSerializedStringVerbatim( $table, $idColumn, $metaId, $value, $objectId, $cacheGroup ) {
		if ( ! $metaId || ! is_string( $value ) ) {
			return;
		}
		$raw = wp_unslash( $value );
		if ( ! is_serialized( $raw, false ) ) {
			return;
		}
		global $wpdb;
		$wpdb->update( $table, array( 'meta_value' => $raw ), array( $idColumn => (int) $metaId ) );
		wp_cache_delete( $objectId, $cacheGroup );
	}

	public function deletePostMeta( $postId, $key ) {
		delete_post_meta( (int) $postId, $key );
	}

	public function updatePostMeta( $postId, $metaKey, $value ) {
		update_post_meta( (int) $postId, $metaKey, $value );
		$metaId = (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT meta_id FROM {$GLOBALS['wpdb']->postmeta} WHERE post_id = %d AND meta_key = %s", (int) $postId, wp_unslash( (string) $metaKey ) ) );
		$this->keepSerializedStringVerbatim( $GLOBALS['wpdb']->postmeta, 'meta_id', $metaId, $value, (int) $postId, 'post_meta' );
	}

	public function postMetaKeys( $postId ) {
		global $wpdb;
		$keys = $wpdb->get_col(
			$wpdb->prepare( "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d", (int) $postId )
		);
		return array_map( 'strval', (array) $keys );
	}

	public function setPostTerms( $postId, $taxonomy, array $termIds, $append = false ) {
		$termIds = array_map( 'intval', $termIds );
		$result  = wp_set_post_terms( (int) $postId, $termIds, $taxonomy, (bool) $append );
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( 'wp_set_post_terms: ' . $result->get_error_message() );
		}
	}

	/* ---- Comments -------------------------------------------------------- */

	public function findCommentId( array $criteria ) {
		$args = array(
			'number'                    => 1,
			'count'                     => false,
			'update_comment_meta_cache' => false,
		);
		if ( isset( $criteria['comment_post_ID'] ) ) {
			$args['post_id'] = (int) $criteria['comment_post_ID'];
		}
		if ( isset( $criteria['comment_author_email'] ) ) {
			$args['author_email'] = $criteria['comment_author_email'];
		}
		if ( isset( $criteria['comment_date_gmt'] ) ) {
			$args['date_query'] = array(
				array(
					'column' => 'comment_date_gmt',
					'before' => $criteria['comment_date_gmt'],
					'after'  => $criteria['comment_date_gmt'],
					'inclusive' => true,
				),
			);
		}
		$found = get_comments( $args );
		if ( ! empty( $found ) ) {
			$first = $found[0];
			return (int) ( is_object( $first ) ? $first->comment_ID : $first );
		}
		return null;
	}

	public function insertComment( array $data ) {
		$id = wp_insert_comment( wp_filter_comment( $data ) );
		if ( ! $id ) {
			throw new \RuntimeException( 'wp_insert_comment returned false' );
		}
		return (int) $id;
	}

	public function addCommentMeta( $commentId, $key, $value ) {
		$metaId = add_comment_meta( (int) $commentId, $key, $value );
		$this->keepSerializedStringVerbatim( $GLOBALS['wpdb']->commentmeta, 'meta_id', $metaId, $value, (int) $commentId, 'comment_meta' );
	}

	public function deleteCommentMeta( $commentId, $key ) {
		delete_comment_meta( (int) $commentId, $key );
	}

	public function updateCommentFields( $commentId, array $fields ) {
		$fields['comment_ID'] = (int) $commentId;
		wp_update_comment( $fields );
	}

	/* ---- Options --------------------------------------------------------- */

	public function getOption( $name, $default = false ) {
		return get_option( $name, $default );
	}

	public function updateOption( $name, $value, $autoload = 'yes' ) {
		update_option( $name, $value, $autoload );
	}

	/* ---- Media ----------------------------------------------------------- */

	public function sideloadMedia( $url, $parentPostId ) {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			throw new \RuntimeException( 'download_url: ' . $tmp->get_error_message() );
		}

		$file_array = array(
			'name'     => basename( parse_url( $url, PHP_URL_PATH ) ),
			'tmp_name' => $tmp,
		);

		$id = media_handle_sideload( $file_array, (int) $parentPostId );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp );
			throw new \RuntimeException( 'media_handle_sideload: ' . $id->get_error_message() );
		}
		return (int) $id;
	}

	public function getAttachmentUrl( $attachmentId ) {
		$url = wp_get_attachment_url( (int) $attachmentId );
		return $url ? (string) $url : null;
	}

	public function findAttachmentByFilename( $filename ) {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( ltrim( $filename, '/' ) );
		$id   = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s LIMIT 1",
				$like
			)
		);
		return $id ? (int) $id : null;
	}
}
