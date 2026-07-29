# Architecture

How the importer is put together, why it's shaped this way, and where to plug
in. It is the mirror image of the exporter: the exporter reads WordPress and
writes JSON; this reads JSON and writes WordPress.

## The central problem

The exported reference graph has cycles and forward references: a post's
`_thumbnail_id` points at an attachment that may not exist yet; comments point
at posts; menus and options point at posts; terms point at parent terms. A
single "create and rewrite as you go" pass cannot resolve them.

So the importer is **two-phase**:

1. **Create phase** — create (or match) every entity and record its
   source → destination id in the ledger. References are *not* resolved here.
2. **Rewrite phase** — with a complete id map, rewrite every reference:
   authorship, hierarchy, term assignments, featured images, comment threading,
   reference-bearing meta, post content, and reference options.

Because all ids exist before any reference is rewritten, **import order never
affects the result**.

## Module layout

```
src/
├── Command.php                 WP-CLI entry point (thin; docblock = CLI spec)
├── Run.php                     orchestrator: phases, ordering, report, exit code
├── Bootstrap.php               builds the default Registry; fires the register hook
├── Snapshot.php                streams the on-disk tree (generators, sorted)
├── Manifest.php                parses manifest.json; schema compatibility; source_key
├── Config.php                  the declarative import-map (defaults + file + CLI)
├── Registry.php                holds resolvers / mappers / strategies / rewriters
├── Context.php                 shared-service bundle passed to every extension point
├── IdMap.php                   semantic facade over the ledger
├── DbLedger.php / ArrayLedger.php   persistent (custom table) / in-memory ledger
├── Decoder.php                 re-slash on write (inverse of the exporter Encoder)
├── Logger.php                  skip/warn events -> report.log
├── Report.php                  import-report.json
├── Json.php                    read + deterministic write
├── Wp.php                      the WordPress gateway (the only WP-touching code)
├── PostColumns.php             extracts wp_posts columns from an entity
├── Contracts/                  interfaces: WordPress, Ledger, Resolver, UserMapper,
│                               MetaMapper, AttachmentStrategy, ReferenceRewriter,
│                               ContentRewriter, EntityImporter
├── Resolver/                   default identity resolvers (user/term/post/comment)
├── Mapper/                     DefaultUserMapper, DefaultMetaMapper
├── Attachment/                 Sideload, ReferenceOnly, MapToExisting, Skip
├── Rewriter/                   CoreReferenceRewriter, DefaultContentRewriter
└── Importer/                   AbstractImporter + Users, Terms, Posts, Comments, Options
```

## Flow of one run

```
wp idempotent-import /snap --config=map.php
   │
Command::__invoke → Run::execute
   ├── Snapshot::assertReadable + Manifest (schema check)
   ├── resolve source_key; load Config; build Ledger (+ create table) → IdMap
   ├── build Context; Bootstrap::defaultRegistry + do_action('idempotent_import_register')
   ├── wp_suspend_cache_addition(true)
   │
   ├── CREATE PHASE   Users → Terms → Posts(+attachments) → Comments → Options
   │      each: ledger check → resolver/mapper → create → record id
   │
   ├── REWRITE PHASE  Users → Terms → Posts → Comments → Options
   │      each: parent/author/terms/thumbnail/meta/content/option refs
   │
   ├── write import-report.json + report.log
   └── exit 0, or halt(1) if any skips
```

Create order matters only to reduce rewrite work (users before posts lets
authorship be set at insert time; posts before comments lets `comment_post_ID`
be set at insert time). Everything genuinely forward-looking is deferred to the
rewrite phase regardless.

## Idempotency & the ledger

`{prefix}idempotent_import_map` stores, per `source_key`:

```
(entity_type, source_id) -> dest_id, content_hash, status, updated_at
```

- **Create** is lookup-then-insert. If a mapping exists and the entity's content
  hash is unchanged, the entity is left untouched (`unchanged`). If it changed,
  `--on-conflict` decides: `update` (rewrite in place, reported as `updated`),
  or `skip`/`recreate` (destination kept, reported as `conflict`).
- **Resumable.** A crashed run resumes from the ledger; re-running is safe.
- **Multi-source.** `source_key` (derived from the manifest's site URL + blog
  id, or `--source-key`) lets one destination absorb several sources without
  id collisions.

The `IdMap` is the semantic view over the ledger. It records six kinds of
mapping: `post` (attachments included), `term` (by term_taxonomy_id), `term_id`
(for parent hierarchy), `ttid_termid` (term_taxonomy_id → dest term_id, for post
assignments), `user`, `comment`, and `url` (source attachment URL → dest URL,
for content rewriting).

## The WordPress gateway

Every WordPress-mutating call goes through the `WordPress` interface
(`src/Contracts/WordPress.php`), implemented in production by `Wp`. This single
seam is why the importers are unit-testable against an in-memory
`FakeWordPress` with no live WordPress — the same reason the exporter mocks at
the `$wpdb` layer.

## Determinism & failure isolation

- The `Snapshot` visits files in sorted path order, so a run's processing order
  — and therefore `report.log` — is stable.
- Per-entity failures (a bad row, a broken attachment URL, an unmapped parent
  post) are **skips**: the entity is omitted, an entry lands in
  `report.log`/`import-report.json`, and the run continues. Skips drive the
  exit code. Recoverable oddities (unmapped reference left as-is) are
  **warnings**.
- Slash symmetry with the exporter keeps `export → import → re-export`
  byte-stable for unchanged content.

## Extension points

In rough order of "I want to" → "I should fork":

1. **Config** — `--config` file: meta renames/drops/refs, user remaps, match
   rules, attachment strategy, options policy. Zero code.
2. **Filters** — `idempotent_import_resolve_{type}`, `idempotent_import_map_user`,
   `idempotent_import_meta`, `idempotent_import_attachment_strategy`,
   `idempotent_import_rewrite_value`, `idempotent_import_rewrite_post_content`,
   `idempotent_import_rewrite_option`, plus lifecycle actions.
3. **Class pipeline** — register `Resolver`, `UserMapper`, `MetaMapper`,
   `AttachmentStrategy`, `ReferenceRewriter`, `ContentRewriter`, or a whole new
   `EntityImporter` on the `idempotent_import_register` hook.

See [EXTENDING.md](EXTENDING.md).

## Known limitations (v1)

- `--on-conflict=update` refreshes columns, content and single-valued meta;
  multi-valued meta is not de-duplicated on update. Unchanged re-imports take
  the fast `matched` path and are unaffected.
- Entities matched to pre-existing destination content are treated as
  authoritative and are not overwritten (non-destructive by design).
- Sideloaded attachments' generated metadata is managed by WordPress and not
  overwritten from the snapshot.
