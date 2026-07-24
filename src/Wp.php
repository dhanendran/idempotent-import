<?php

namespace IdempotentImport;

use IdempotentImport\Contracts\WordPress;

/**
 * Production WordPress gateway. Thin delegations to WP core functions.
 *
 * WP_Error returns are converted to RuntimeExceptions so the importers can
 * treat any failure uniformly as a per-entity skip.
 */
class Wp implements WordPress {

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
		add_user_meta( (int) $userId, $key, $value );
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

	public function addTermMeta( $termId, $key, $value ) {
		add_term_meta( (int) $termId, $key, $value );
	}

	public function updateTermParent( $termId, $taxonomy, $parentTermId ) {
		wp_update_term( (int) $termId, $taxonomy, array( 'parent' => (int) $parentTermId ) );
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

	public function insertPost( array $data ) {
		$id = wp_insert_post( $data, true );
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( 'wp_insert_post: ' . $id->get_error_message() );
		}
		return (int) $id;
	}

	public function updatePostFields( $postId, array $fields ) {
		$fields['ID'] = (int) $postId;
		$result       = wp_update_post( $fields, true );
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( 'wp_update_post: ' . $result->get_error_message() );
		}
	}

	public function addPostMeta( $postId, $key, $value ) {
		add_post_meta( (int) $postId, $key, $value );
	}

	public function updatePostMeta( $postId, $metaKey, $value ) {
		update_post_meta( (int) $postId, $metaKey, $value );
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
		add_comment_meta( (int) $commentId, $key, $value );
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
