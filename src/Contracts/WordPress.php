<?php

namespace IdempotentImport\Contracts;

/**
 * The single seam between the importer and WordPress's mutating APIs.
 *
 * Everything that touches the database or the media library goes through this
 * interface, so the importers are exercised in tests against an in-memory fake
 * instead of a live WordPress. The production implementation (Wp) delegates to
 * wp_insert_post, wp_insert_term, wp_insert_comment, wp_insert_user,
 * add_*_meta, update_option and media_handle_sideload.
 *
 * All string inputs are expected to be already slashed by the Decoder, matching
 * WordPress core's own expectations.
 */
interface WordPress {

	/* ---- Users ----------------------------------------------------------- */

	/**
	 * @param string $field login|email|slug
	 * @param string $value
	 * @return int|null Existing user id or null.
	 */
	public function getUserIdBy( $field, $value );

	/**
	 * Insert a user with a random password (import forces resets).
	 *
	 * @param array $data wp_users columns (no user_pass).
	 * @return int New user id.
	 * @throws \RuntimeException On failure.
	 */
	public function insertUser( array $data );

	/**
	 * @param int    $userId
	 * @param string $key
	 * @param mixed  $value
	 * @return void
	 */
	public function addUserMeta( $userId, $key, $value );

	/* ---- Terms ----------------------------------------------------------- */

	/**
	 * @param string $taxonomy
	 * @param string $field slug|name
	 * @param string $value
	 * @return array{term_id:int,term_taxonomy_id:int}|null
	 */
	public function getTermBy( $taxonomy, $field, $value );

	/**
	 * @param string $name
	 * @param string $taxonomy
	 * @param array  $args slug, description, parent (dest term_id)
	 * @return array{term_id:int,term_taxonomy_id:int}
	 * @throws \RuntimeException On failure.
	 */
	public function insertTerm( $name, $taxonomy, array $args );

	/**
	 * @param int    $termId
	 * @param string $key
	 * @param mixed  $value
	 * @return void
	 */
	public function addTermMeta( $termId, $key, $value );

	/**
	 * @param int    $termId
	 * @param string $taxonomy
	 * @param int    $parentTermId Destination term_id.
	 * @return void
	 */
	public function updateTermParent( $termId, $taxonomy, $parentTermId );

	/* ---- Posts ----------------------------------------------------------- */

	/**
	 * @param string $field name|guid
	 * @param string $value
	 * @param string $postType
	 * @return int|null
	 */
	public function getPostIdBy( $field, $value, $postType );

	/**
	 * @param array $data wp_posts columns.
	 * @return int New post id.
	 * @throws \RuntimeException On failure.
	 */
	public function insertPost( array $data );

	/**
	 * Update a subset of a post's columns (used in the rewrite phase for
	 * post_author / post_parent / post_content).
	 *
	 * @param int   $postId
	 * @param array $fields
	 * @return void
	 */
	public function updatePostFields( $postId, array $fields );

	/**
	 * @param int    $postId
	 * @param string $key
	 * @param mixed  $value
	 * @return void
	 */
	public function addPostMeta( $postId, $key, $value );

	/**
	 * @param int    $postId
	 * @param string $metaKey
	 * @param mixed  $value
	 * @return void
	 */
	public function updatePostMeta( $postId, $metaKey, $value );

	/**
	 * Assign terms (destination term ids) to a post within a taxonomy.
	 *
	 * @param int    $postId
	 * @param string $taxonomy
	 * @param int[]  $termIds Destination term ids.
	 * @param bool   $append
	 * @return void
	 */
	public function setPostTerms( $postId, $taxonomy, array $termIds, $append = false );

	/* ---- Comments -------------------------------------------------------- */

	/**
	 * @param array $criteria e.g. comment_post_ID, comment_author_email, comment_date_gmt
	 * @return int|null
	 */
	public function findCommentId( array $criteria );

	/**
	 * @param array $data wp_comments columns.
	 * @return int New comment id.
	 * @throws \RuntimeException On failure.
	 */
	public function insertComment( array $data );

	/**
	 * @param int    $commentId
	 * @param string $key
	 * @param mixed  $value
	 * @return void
	 */
	public function addCommentMeta( $commentId, $key, $value );

	/**
	 * @param int   $commentId
	 * @param array $fields comment_post_ID, user_id, comment_parent
	 * @return void
	 */
	public function updateCommentFields( $commentId, array $fields );

	/* ---- Options --------------------------------------------------------- */

	/**
	 * @param string $name
	 * @param mixed  $default
	 * @return mixed
	 */
	public function getOption( $name, $default = false );

	/**
	 * @param string      $name
	 * @param mixed       $value
	 * @param string|bool $autoload 'yes'|'no'|true|false
	 * @return void
	 */
	public function updateOption( $name, $value, $autoload = 'yes' );

	/* ---- Media ----------------------------------------------------------- */

	/**
	 * Download a remote URL into the media library and attach it to a post.
	 *
	 * @param string $url
	 * @param int    $parentPostId
	 * @return int New attachment id.
	 * @throws \RuntimeException On failure.
	 */
	public function sideloadMedia( $url, $parentPostId );
}
