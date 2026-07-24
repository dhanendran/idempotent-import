# WP Idempotent Import

A WP-CLI command that imports a deterministic JSON snapshot — produced by
[**WP Idempotent Export**](https://github.com/darylldoyle/idempotent-export) —
into a WordPress site, **idempotently** and **without wiping the destination**.

The exporter deliberately preserves source WordPress IDs as canonical
identifiers and does no reference rewriting; it leaves ID reissuing and
reference rewriting to the importer. This project is that importer.

```
wp idempotent-import /path/to/snapshot
```

Re-running the same snapshot against the same destination reconciles against a
persisted id-map ledger instead of creating duplicates, so imports are safe to
repeat and to resume.

## What it does

- Reads the exporter's tree (`manifest.json`, `posts/`, `terms/`, `users/`,
  `comments/`, `options.json`) and recreates each entity on the destination.
- **Reissues IDs** and **rewrites every reference** — post author and parent,
  term hierarchy and assignments, featured images, comment threading, and
  reference-bearing meta / options — in a second pass, so import order never
  matters.
- **Never deletes.** Existing destination content is matched and merged into by
  configurable identity rules; only what's missing is created.
- **Idempotent & resumable** via a custom ledger table keyed by
  `(source site, entity type, source id)`.

## Built to be extended

Every project migrates differently, so the importer is generic at its core and
project-specific at its edges. Three things you'll almost always need are
first-class:

- **Meta-key mapping** — rename, drop, merge, or retype meta keys (old key →
  new key), declaratively in config or via a `MetaMapper` class.
- **User mapping** — collapse several source authors into one, remap by rule,
  reuse existing accounts, or skip — via config or a `UserMapper` class.
- **Attachment mapping** — pluggable strategy, **sideload by default**
  (download into the media library), with reference-only, map-to-existing and
  skip alternatives.

Everything is reachable two ways: lightweight **WordPress filters** for small
tweaks, and a registered **class pipeline** (resolvers, mappers, strategies,
rewriters) for heavier logic. See [`docs/EXTENDING.md`](docs/EXTENDING.md).

## Install

Distributed as a WP-CLI package / WordPress plugin.

```
git clone https://github.com/dhanendran/idempotent-import.git \
    wp-content/plugins/idempotent-import
wp plugin activate idempotent-import
```

Or include it as a Composer dependency in a plugin/mu-plugin bundle. The plugin
only registers itself when `WP_CLI` is loaded, so it has no front-end cost.

PHP 7.4+ for the runtime. PHP 8.1+ for the test suite.

## Quickstart

```
# Import a full snapshot.
wp idempotent-import /tmp/snapshot

# Use a project import-map (meta renames, user remaps, attachment strategy).
wp idempotent-import /tmp/snapshot --config=migration-map.php

# Preview only — report what would happen, write nothing.
wp idempotent-import /tmp/snapshot --dry-run

# Restrict to some entity types.
wp idempotent-import /tmp/snapshot --only=users,terms,posts

# Keep external/CDN media instead of downloading it.
wp idempotent-import /tmp/snapshot --attachments=reference
```

A clean run exits zero. Any per-entity skip produces a non-zero exit; skips are
listed in `import-report.json` and `report.log` written next to the snapshot's
`manifest.json`.

## Documentation

- [`docs/USAGE.md`](docs/USAGE.md) — every flag, exit codes, recipes.
- [`docs/INPUT.md`](docs/INPUT.md) — the snapshot format this importer consumes
  (the exporter's output contract), and how each field is mapped.
- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — the two-phase model, the
  ledger, module layout, and where to extend.
- [`docs/EXTENDING.md`](docs/EXTENDING.md) — config-driven mapping, filters, and
  the class pipeline, with worked examples.
- [`docs/TESTING.md`](docs/TESTING.md) — running the Pest suite and the
  in-memory WordPress test double.

## Relationship to the exporter

This importer targets the output of
[`darylldoyle/idempotent-export`](https://github.com/darylldoyle/idempotent-export)
schema `1.x`. It mirrors the exporter's conventions deliberately: the same
deterministic JSON style for its report, the same skip/warn logging model, the
same WP-CLI shape, and an inverse of the exporter's slash handling so that
export → import → re-export stays byte-stable.

## Non-goals

- Network-wide multisite import. One site at a time.
- Migrating binary media beyond the sideload strategy.
- Importing plugin custom tables (HPOS, Gravity Forms, etc.) — the exporter
  doesn't emit them yet; when it does (via its planned third-party-table hook),
  a matching importer hook will consume them.
- Validating the source schema.

## License

MIT.
