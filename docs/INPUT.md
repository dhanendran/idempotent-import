# Input format

The importer consumes the tree emitted by
[WP Idempotent Export](https://github.com/darylldoyle/idempotent-export). This
document summarises that contract and, for each field, how the importer maps it
onto the destination. The exporter's
[`OUTPUT.md`](https://github.com/darylldoyle/idempotent-export/blob/main/docs/OUTPUT.md)
is the authoritative description of the format itself.

## Directory layout

```
{snapshot}/
├── manifest.json
├── options.json
├── posts/{YYYY}/{MM}/{ID}.json
├── terms/{taxonomy}/{term_taxonomy_id}.json
├── users/{ID}.json
└── comments/{YYYY}/{MM}/{ID}.json
```

Filenames use **source** WordPress IDs. The importer treats those IDs as
opaque source keys and assigns fresh destination IDs, recording the mapping in
its ledger.

## Load-bearing conventions

- **Meta is `{ key: [value, ...] }`** — always a list, even for single values.
  Values are already JSON (the exporter unserialized PHP). The importer feeds
  each value back through `add_*_meta`, which re-serializes arrays. Postmeta
  scalar values are strings (e.g. `_thumbnail_id` is `"8821"`); the importer
  preserves the scalar type unless a key is declared `numeric`.
- **Terms are keyed by `term_taxonomy_id`.** A post's `terms` map is
  `{ taxonomy: [term_taxonomy_id, ...] }`. Each term file carries both
  `term_id` and `term_taxonomy_id`, and `parent` (a `term_id`). The importer
  records three mappings per term so it can resolve every kind of term
  reference (see ARCHITECTURE.md).
- **Slashing.** The exporter calls `wp_unslash()` once before encoding. The
  importer re-slashes (`wp_slash`) before every insert, because WordPress's
  insert APIs expect slashed input — keeping content byte-stable across
  round trips.

## manifest.json

Read for:

- `schema_version` — compatibility check (major version must match; `--force`
  overrides).
- `source.site_url` + `source.blog_id` — derive the ledger `source_key`.
- `source`, `counts` — copied into `import-report.json` for provenance.

## Per-entity mapping

### Users (`users/{ID}.json`)

| Field | Handling |
| --- | --- |
| `user_login`, `user_email`, `user_nicename`, `user_url`, `display_name`, `user_registered`, `user_status` | Passed to `wp_insert_user`. |
| `user_pass` | Absent by design. Created users get a random password; force a reset. |
| `meta` (incl. `wp_capabilities`) | Written as user meta. `role_map` config remaps roles inside `wp_capabilities`. |

Policy: `UserMapper` decides reuse / create / remap / skip; the resolver matches
existing users by `user_login` / `user_email`.

### Terms (`terms/{taxonomy}/{term_taxonomy_id}.json`)

| Field | Handling |
| --- | --- |
| `name`, `slug`, `description`, `taxonomy` | Passed to `wp_insert_term`. |
| `parent` (source `term_id`) | Set in the rewrite phase, mapped to the destination `term_id`. |
| `term_id`, `term_taxonomy_id` | Recorded in the ledger (both, plus a ttid→term_id shortcut). |
| `meta` | Written as term meta. |

Resolver matches existing terms by `(taxonomy, slug)`.

### Posts & attachments (`posts/{YYYY}/{MM}/{ID}.json`)

| Field | Handling |
| --- | --- |
| Own columns (`post_title`, `post_content`, `post_status`, dates, `post_name`, `guid`, `menu_order`, `post_type`, `post_mime_type`, …) | Inserted verbatim (re-slashed). |
| `post_author` | Mapped to the destination user (users import first); falls back to `default_author`. |
| `post_parent` | Mapped in the rewrite phase. |
| `terms` (`{taxonomy:[ttid]}`) | Assigned via `wp_set_post_terms` using destination term ids. |
| `comments` (`[id]`) | Ignored — comment files are authoritative; `comment_count` is recomputed by WordPress. |
| `meta._thumbnail_id` | Rewritten to the destination attachment id. |
| `meta.*` declared as refs | Rewritten per declared type (see EXTENDING.md). |
| `post_content` | Block ids, `wp-image-{id}` classes and known attachment URLs rewritten. |
| `attachment_url` (attachments) | Handled by the attachment strategy: sideload downloads it; reference keeps it; a source-URL → dest-URL map drives content rewriting. |

### Comments (`comments/{YYYY}/{MM}/{ID}.json`)

| Field | Handling |
| --- | --- |
| `comment_post_ID` | Mapped to destination post; comment is skipped if the post is missing. |
| `user_id` | Mapped to destination user, or `0` for anonymous. |
| `comment_parent` | Mapped in the rewrite phase (threading). |
| Other columns | Inserted verbatim. |
| `meta` | Written as comment meta. |

### Options (`options.json`)

`{ option_name: { autoload, value } }`. Applied in the rewrite phase, subject to
the `options` policy (`none` / `allowlist` / `all`, plus deny list). Known
reference options (`page_on_front`, `page_for_posts`,
`wp_page_for_privacy_policy`, `sticky_posts`, `default_category`, …) are
remapped to destination ids. `autoload` is preserved.

## Things the importer intentionally does not do

- It does not delete or overwrite destination entities it merely *matched*
  (only entities it created/updated get their meta and references written).
- It does not import `active_plugins`, `cron`, `siteurl` or `home` unless you
  explicitly opt in via config.
- It does not validate the source snapshot's internal consistency; dangling
  references (from mid-export source mutations) are logged and skipped.
