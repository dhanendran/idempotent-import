# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/), and the project adheres to
semantic versioning.

## [1.1.0]

### Added
- **Restore deleted content on re-import.** Each importer reconciles the ledger
  against the destination (one bounded anti-join per entity type per run) and
  re-imports anything deleted outside the importer, reported as `restored`.
  Past `MISSING_LIMIT` the destination is treated as wiped.
- **Multisite role handling.** Users matched to an existing network account get
  their per-blog `wp_capabilities` / `wp_user_level` attached (rebased onto the
  destination blog's prefix), and roles are re-attached when a user was removed
  from a blog — without touching the global profile or creating duplicate
  accounts.
- **Richer outcome reporting.** The single `matched` outcome was split into
  `created` / `matched` / `updated` / `restored` / `unchanged` / `conflict` /
  `skipped`, each printed on its own line with its meaning. Import report schema
  bumped to `1.1.0`.
- `--verbose` prints a per-entity decision line to the console and `report.log`.
- New gateway/ledger APIs: `Ledger::sqlIdentity()`,
  `WordPress::missingDestIds()`, `nonMemberUserIds()`, and
  `delete{User,Post,Term,Comment}Meta()`.

### Fixed
- **Meta is replaced, not appended.** `writeMeta()` clears existing rows per key
  before writing, so WordPress's seeded defaults no longer win over the
  snapshot, and re-imports no longer accumulate duplicate meta rows.
- A cheap password hasher is swapped in for the run (imported users get a
  throwaway password and must reset), removing bcrypt cost from import time.

### Changed
- Composer package renamed to `dhanendran/idempotent-import`.

## [1.0.0]

Initial release: two-phase (create-then-rewrite) WP-CLI importer for
[WP Idempotent Export](https://github.com/darylldoyle/idempotent-export)
snapshots. Idempotent via a source-keyed ledger, non-destructive, with
config / filter / class extension points for meta-key, user, and attachment
mapping. Ships users, terms, posts (incl. attachments), comments, and options
importers, a Pest test suite, and full user-facing docs.
