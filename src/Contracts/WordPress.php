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

	/**
	 * Most missing ids either reconciliation query will list. Past this the
	 * destination has been wiped rather than edited, and callers should treat the
	 * whole type as missing instead of materialising millions of ids.
	 */
	const MISSING_LIMIT = 50000;

	/**
	 * wp_posts columns WordPress derives rather than accepts.
	 *
	 * wp_insert_post() re-decides post_status by comparing post_date against now,
	 * pushes post_name through wp_unique_post_slug(), and always stamps
	 * post_modified itself. In a migration those are the source's to dictate — a
	 * scheduled post must not go live, a duplicate slug must not be renamed, and an
	 * edit date must not become the import date — so implementations pin whichever
	 * of these the caller supplied for the duration of the write.
	 */
	const PRESERVED_COLUMNS = array(
		'post_status',
		'post_name',
		'post_date',
		'post_date_gmt',
		'post_modified',
		'post_modified_gmt',
	);

	/* ---- Destination reconciliation -------------------------------------- */

	/**
	 * Destination ids the ledger has recorded for a type that are no longer there.
	 *
	 * The ledger records what the importer did, not what the destination looks
	 * like now. Anything deleted outside the importer has to be detected here or
	 * a re-import skips it forever.
	 *
	 * Answered by the database as an anti-join against the ledger table, so the
	 * result is proportional to what went missing (normally nothing) rather than
	 * to how much has ever been imported.
	 *
	 * @param string $type   user|post|term|comment ('term' ids are term_taxonomy_ids).
	 * @param Ledger $ledger Supplies its own table and source key.
	 * @return int[] Missing destination ids, at most MISSING_LIMIT + 1 of them.
	 */
	public function missingDestIds( $type, Ledger $ledger );

	/**
	 * Ledger users who no longer belong to the destination blog.
	 *
	 * Separate from missingDestIds() because accounts are network-global: removing
	 * someone from a site deletes their per-blog capabilities and leaves the
	 * account intact.
	 *
	 * @param Ledger $ledger
	 * @return int[] Non-member ids, at most MISSING_LIMIT + 1 of them.
	 */
	public function nonMemberUserIds( Ledger $ledger );

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

	/**
	 * Remove all of a user's meta rows for a key (replace-before-write).
	 *
	 * @param int    $userId
	 * @param string $key
	 * @return void
	 */
	public function deleteUserMeta( $userId, $key );

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
	 * Insert a term under its source term_id and term_taxonomy_id.
	 *
	 * wp_insert_term() has no `import_id` equivalent, so implementations write
	 * wp_terms and wp_term_taxonomy directly.
	 *
	 * Callers must confirm the IDs are free first (getTermRow / getTermTaxonomyRow);
	 * this method does not arbitrate collisions.
	 *
	 * @param int    $termId Pass 0 to let the destination assign one — a source
	 *                       term_id already in use by another taxonomy's term, i.e.
	 *                       a shared term being split (see Terms::insert()).
	 * @param int    $ttId
	 * @param string $name
	 * @param string $taxonomy
	 * @param array  $args slug, description, parent (source term_id), count, term_group
	 * @return array{term_id:int,term_taxonomy_id:int}
	 * @throws \RuntimeException On failure.
	 */
	public function insertTermWithIds( $termId, $ttId, $name, $taxonomy, array $args );

	/**
	 * Is a taxonomy registered on the destination?
	 *
	 * wp_insert_term() refuses an unregistered taxonomy, but a preserved-ID insert
	 * writes the rows directly and bypasses that check — leaving terms WordPress
	 * cannot query and post assignments that fail one by one. Callers check first so
	 * the run reports a skip instead.
	 *
	 * @param string $taxonomy
	 * @return bool
	 */
	public function taxonomyExists( $taxonomy );

	/**
	 * The wp_terms row occupying a term_id, or null if the ID is free.
	 *
	 * @param int $termId
	 * @return array{term_id:int,name:string,slug:string,term_group:int}|null
	 */
	public function getTermRow( $termId );

	/**
	 * The wp_term_taxonomy row occupying a term_taxonomy_id, or null if free.
	 *
	 * @param int $ttId
	 * @return array{term_taxonomy_id:int,term_id:int,taxonomy:string,parent:int}|null
	 */
	public function getTermTaxonomyRow( $ttId );

	/**
	 * @param int    $termId
	 * @param string $key
	 * @param mixed  $value
	 * @return void
	 */
	public function addTermMeta( $termId, $key, $value );

	/**
	 * @param int    $termId
	 * @param string $key
	 * @return void
	 */
	public function deleteTermMeta( $termId, $key );

	/**
	 * Update a subset of a term's columns.
	 *
	 * Used in the rewrite phase for `parent` (a destination term_id) and, when a
	 * previously-imported term changed at the source, to re-sync name / slug /
	 * description / term_group.
	 *
	 * @param int    $termId
	 * @param string $taxonomy
	 * @param array  $fields name, slug, description, parent, term_group
	 * @return void
	 */
	public function updateTermFields( $termId, $taxonomy, array $fields );

	/**
	 * Raise the term tables' AUTO_INCREMENT so terms created after the migration
	 * cannot reuse a migrated ID (spec 3.3.2). Never lowers either.
	 *
	 * @param int $nextTermId From manifest.source.auto_increment.terms.
	 * @param int $nextTtId   From manifest.source.auto_increment.term_taxonomy,
	 *                        or the highest ttid the run imported plus one.
	 * @return void
	 */
	public function setTermsAutoIncrement( $nextTermId, $nextTtId );

	/* ---- Posts ----------------------------------------------------------- */

	/**
	 * @param string $field name|guid
	 * @param string $value
	 * @param string $postType
	 * @return int|null
	 */
	public function getPostIdBy( $field, $value, $postType );

	/**
	 * The destination row occupying a post ID, or null if the ID is free.
	 *
	 * Needed when IDs are preserved: the importer has to know whether an ID is
	 * unoccupied before claiming it, and whether an occupant is the same post
	 * (safe to adopt) or unrelated content (a collision to report).
	 *
	 * @param int $postId
	 * @return array|null At least post_type and post_name.
	 */
	public function getPost( $postId );

	/**
	 * @param array $data wp_posts columns. An `import_id` key requests that ID
	 *                    (WordPress silently ignores it if the ID is taken, so
	 *                    callers must verify the returned id).
	 * @return int New post id.
	 * @throws \RuntimeException On failure.
	 */
	public function insertPost( array $data );

	/**
	 * Raise the posts table's AUTO_INCREMENT so new content cannot reuse a
	 * migrated ID. Never lowers it.
	 *
	 * @param int $nextId
	 * @return void
	 */
	public function setPostsAutoIncrement( $nextId );

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
	 * @param string $key
	 * @return void
	 */
	public function deletePostMeta( $postId, $key );

	/**
	 * @param int    $postId
	 * @param string $metaKey
	 * @param mixed  $value
	 * @return void
	 */
	public function updatePostMeta( $postId, $metaKey, $value );

	/**
	 * Every meta key currently on a post.
	 *
	 * Lets the importer drop keys the snapshot no longer has, so a key deleted at
	 * the source (or seeded by WordPress on insert) does not linger on the
	 * destination for the rest of the migration's life.
	 *
	 * @param int $postId
	 * @return string[]
	 */
	public function postMetaKeys( $postId );

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
	 * @param int    $commentId
	 * @param string $key
	 * @return void
	 */
	public function deleteCommentMeta( $commentId, $key );

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

	/**
	 * Resolve the public URL of an attachment on the destination.
	 *
	 * @param int $attachmentId
	 * @return string|null
	 */
	public function getAttachmentUrl( $attachmentId );

	/**
	 * Find an existing attachment id by its original source filename
	 * (basename of _wp_attached_file). Used by the map-existing strategy.
	 *
	 * @param string $filename
	 * @return int|null
	 */
	public function findAttachmentByFilename( $filename );
}
