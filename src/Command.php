<?php

namespace IdempotentImport;

class Command {

	/**
	 * Import a deterministic JSON snapshot (produced by WP Idempotent Export) into this site.
	 *
	 * The importer is idempotent and non-destructive: it never wipes the destination,
	 * and re-running the same snapshot reconciles against a persisted id-map ledger
	 * rather than creating duplicates. Source WordPress IDs are reissued by default
	 * (use --preserve-ids to keep them) and all references are rewritten in a second
	 * pass, so import order never matters.
	 *
	 * ## OPTIONS
	 *
	 * <snapshot-dir>
	 * : Directory containing manifest.json and the exported entity tree.
	 *
	 * [--config=<file>]
	 * : Path to an import-map config file (.php returning an array, or .json).
	 *
	 * [--source-key=<key>]
	 * : Identity of the source site, used to namespace the id-map ledger. Defaults to a
	 *   hash of manifest.source.site_url + blog_id. Set explicitly when re-importing a
	 *   snapshot whose source URL changed.
	 *
	 * [--dry-run]
	 * : Plan only. Report what would be created / matched / skipped, write nothing. Exits zero.
	 *
	 * [--only=<csv>]
	 * : Restrict to a subset of entity types (users,terms,posts,comments,options).
	 *
	 * [--skip=<csv>]
	 * : Exclude entity types.
	 *
	 * [--on-conflict=<mode>]
	 * : What to do when a previously-imported entity's content changed.
	 *   One of: update, skip, recreate. Default: update.
	 *
	 * [--preserve-ids]
	 * : Insert posts under their source IDs, and terms under their source term_id and
	 *   term_taxonomy_id, instead of reissuing — then raise those tables' AUTO_INCREMENT
	 *   past the snapshot. Requires a destination with no content at those IDs — an
	 *   occupied ID is reported as a skip, never reissued. Note `wp site empty` re-seeds
	 *   a default category, so delete it unless the source holds the same term at the
	 *   same ID. Use with --attachments=reference; sideloaded media cannot keep its
	 *   source ID.
	 *
	 * [--attachments=<strategy>]
	 * : Attachment handling strategy: sideload, reference, map-existing, skip. Default: sideload.
	 *
	 * [--default-author=<id>]
	 * : Destination user ID to fall back to when a source author cannot be mapped. Default: 1.
	 *
	 * [--options=<mode>]
	 * : Options policy: none, allowlist, all. Default: allowlist.
	 *
	 * [--batch-size=<n>]
	 * : Entities processed per batch. Default 500.
	 *
	 * [--inter-batch-sleep-ms=<ms>]
	 * : Sleep between batches. Default 0. Recommend 50-100 on VIP.
	 *
	 * [--blog-id=<id>]
	 * : Destination blog on multisite. Required there.
	 *
	 * [--quiet]
	 * : Suppress progress bars.
	 *
	 * [--verbose]
	 * : Print a per-entity decision line (created / matched / skipped, and why).
	 *
	 * [--force]
	 * : Proceed despite a manifest schema_version the importer does not recognise.
	 *
	 * ## EXAMPLES
	 *
	 *     wp idempotent-import /tmp/snapshot
	 *     wp idempotent-import /tmp/snapshot --config=migration-map.php --attachments=sideload
	 *     wp idempotent-import /tmp/snapshot --only=posts,terms --dry-run
	 *     wp idempotent-import /tmp/snapshot --only=users,terms,posts --preserve-ids --attachments=reference
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments / flags.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		( new Run() )->execute( $args, $assoc_args );
	}
}
