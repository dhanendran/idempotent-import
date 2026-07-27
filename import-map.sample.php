<?php
/**
 * Sample import-map for `wp idempotent-import <snapshot> --config=import-map.sample.php`.
 *
 * Copy this file, trim it to what your migration needs, and point --config at it.
 * Every section is optional; omitted keys fall back to the safe defaults in
 * IdempotentImport\Config::defaults(). See docs/EXTENDING.md for the full
 * reference.
 */

return array(

	// ---- Users ---------------------------------------------------------------
	'users'       => array(
		'match_by'       => array( 'user_login', 'user_email' ),
		'remap'          => array(
			// sourceUserId => destUserId  (collapse or redirect authors)
			// 12 => 7,
		),
		'role_map'       => array(
			// 'contributor' => 'author',
		),
		'on_missing'     => 'create', // create | skip | assign_default
		'default_author' => 1,
	),

	// ---- Terms ---------------------------------------------------------------
	'terms'       => array(
		'match_by' => array( 'taxonomy', 'slug' ),
	),

	// ---- Posts ---------------------------------------------------------------
	'posts'       => array(
		'match_by'     => array( 'post_type', 'post_name' ), // add 'guid' to match on GUID
		'status_map'   => array(),
		// true: insert posts under their source IDs rather than reissuing. Needs a
		// destination with no content at those IDs; an occupied ID becomes a skip.
		'preserve_ids' => false,
	),

	// ---- Attachments ---------------------------------------------------------
	'attachments' => array(
		'strategy' => 'sideload', // sideload | reference | map-existing | skip
	),

	// ---- Meta ----------------------------------------------------------------
	'meta'        => array(
		'post' => array(
			'rename'  => array(
				// 'old_seo_title' => '_yoast_wpseo_title',
			),
			'drop'    => array(
				// '_edit_lock', '_edit_last',
			),
			'numeric' => array(
				// '_thumbnail_id',
			),
			'refs'    => array(
				// 'hero_image'    => 'attachment',
				// 'related_posts' => 'post[]',
				// 'assigned_to'   => 'user',
			),
		),
	),

	// ---- Options -------------------------------------------------------------
	'options'     => array(
		'mode'  => 'allowlist', // none | allowlist | all
		'allow' => array( 'blogname', 'blogdescription', 'date_format', 'time_format', 'start_of_week' ),
		'deny'  => array( 'cron', 'active_plugins', 'siteurl', 'home' ),
	),
);
