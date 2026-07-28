# Usage

```
wp idempotent-import <snapshot-dir> [flags]
```

`<snapshot-dir>` is a directory produced by
[WP Idempotent Export](https://github.com/darylldoyle/idempotent-export). It
must contain a `manifest.json`.

## Flags

| Flag | Default | Description |
| --- | --- | --- |
| `--config=<file>` | — | Import-map config. `.php` returning an array, or `.json`. See [EXTENDING.md](EXTENDING.md). |
| `--source-key=<key>` | derived | Namespaces the id-map ledger. Defaults to a hash of `manifest.source.site_url` + `blog_id`. Set explicitly when the source URL changed between exports. |
| `--dry-run` | off | Plan only: report what each entity would become, write nothing. Always exits zero. |
| `--only=<csv>` | all | Limit to entity types: `users,terms,posts,comments,options`. |
| `--skip=<csv>` | — | Exclude entity types. |
| `--on-conflict=<mode>` | `update` | When a previously-imported entity's content changed: `update`, `skip`, or `recreate`. |
| `--attachments=<strategy>` | `sideload` | `sideload`, `reference`, `map-existing`, or `skip`. |
| `--default-author=<id>` | `1` | Destination user id used when a source author can't be mapped. |
| `--options=<mode>` | `allowlist` | `none`, `allowlist`, or `all`. |
| `--batch-size=<n>` | `500` | Entities processed per batch. |
| `--inter-batch-sleep-ms=<ms>` | `0` | Sleep between batches. Recommend 50–100 on VIP. |
| `--blog-id=<id>` | — | Destination blog on multisite. Required there. |
| `--quiet` | off | Suppress progress output. |
| `--force` | off | Proceed despite an unrecognised manifest `schema_version`. |

## Summary outcomes

Every entity lands in exactly one outcome, printed as its own line per entity
type; the lines sum to the type's total.

| Outcome | Meaning |
| --- | --- |
| `created` | New — inserted into the destination. |
| `matched` | Linked to existing destination content for the first time (e.g. a network user account already had that login/email). |
| `updated` | Source changed since the last import; re-synced. |
| `unchanged` | No-op — already imported and the source has not changed. |
| `restored` | The ledger had it as imported but it was missing from the destination (deleted outside the importer), so it was re-imported. |
| `conflict` | Source changed but the destination was kept, because `--on-conflict` is not `update`. Recurs every run until resolved. |
| `skipped` | NOT imported — excluded by a rule, or failed. |

A re-run of an unchanged snapshot puts the whole total under `unchanged` and
ends with `Nothing to do: all N entities were already imported and unchanged.`
That line, not the total, is the idempotence check.

## Exit codes

- `0` — clean run (or any `--dry-run`).
- `1` — at least one entity was skipped. Skips are listed in
  `import-report.json` (`skipped[]`) and `report.log`.

Fatal problems (missing snapshot, incompatible schema without `--force`,
missing `--blog-id` on multisite) abort immediately with a non-zero exit.

## What gets written

Next to the snapshot's `manifest.json`, a successful (non-dry-run) run writes:

- `import-report.json` — outcome counts per entity type (created / matched /
  updated / unchanged / conflict / skipped), the resolved `source_key`, the
  source metadata copied from the manifest, and a sorted `skipped[]` list. The
  importer's analogue of the exporter's manifest.
- `report.log` — one tab-separated line per skip/warn event, in processing
  order, for `grep`/`tail` during long runs.

The ledger table (`{prefix}idempotent_import_map`) is created in the
destination database on first run and persists across runs.

## Recipes

Import everything, sideloading media:

```
wp idempotent-import /tmp/snapshot
```

Dry-run a large migration to see the plan first:

```
wp idempotent-import /tmp/snapshot --dry-run
```

Migrate content but collapse all source authors to editor #7 and rename a
legacy SEO meta key (via config):

```
wp idempotent-import /tmp/snapshot --config=migration-map.php
```

```php
// migration-map.php
return [
    'users' => [ 'remap' => [ 12 => 7, 34 => 7, 56 => 7 ] ],
    'meta'  => [ 'post' => [ 'rename' => [ 'old_seo_title' => '_yoast_wpseo_title' ] ] ],
];
```

Keep media on the existing CDN instead of downloading:

```
wp idempotent-import /tmp/snapshot --attachments=reference
```

Re-import after fixing source data, updating changed entities in place:

```
wp idempotent-import /tmp/snapshot --on-conflict=update
```

Import only taxonomy and posts into a site whose users already exist:

```
wp idempotent-import /tmp/snapshot --only=terms,posts
```

## Multisite

```
wp idempotent-import /tmp/snapshot --blog-id=5
```

`--blog-id` is required on multisite; the importer switches to that blog for the
run and restores afterwards. One blog per invocation.

Accounts are network-global but roles are per-blog, so a user matched to an existing
account is still given the snapshot's role **for this blog**, rebasing
`wp_capabilities` / `wp_user_level` onto the destination prefix. Their global profile
is untouched.

This is the one place matched content is not left authoritative: an account already
holding a role on this blog is overwritten, so a destination Editor is downgraded if
the snapshot says Subscriber. That is what a migration wants. To keep the
destination's own roles instead:

```php
'users' => array(
    'attach_roles_to_matched' => false,
),
```

With it off, matched users keep whatever role they already had, and a user importing
into their second site gets no role there.

## Safety notes

- **Options are conservative by default.** In `allowlist` mode only a small safe
  set is written (`blogname`, `blogdescription`, date/time formats, …).
  `cron`, `active_plugins`, `siteurl` and `home` are denied even in `all` mode
  unless you remove them from the deny list in config.
- **Passwords are never imported** (the exporter strips them). Created users get
  a random password and must reset it.
- **Attachments are sideloaded from the exported URLs.** Broken source URLs
  produce skips, logged per attachment.
