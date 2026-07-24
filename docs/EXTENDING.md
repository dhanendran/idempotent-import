# Extending the importer

The importer is generic at its core and project-specific at its edges. There are
three levels of extension, from least to most effort:

1. **Config** — a declarative import-map file. No code.
2. **Filters** — small WordPress hooks for per-entity tweaks.
3. **Classes** — register your own resolver / mapper / strategy / rewriter, or a
   whole new entity type.

Precedence is **config → filters → classes**: classes may consult config, and
filters wrap the default classes.

---

## 1. The config import-map

Pass `--config=path/to/map.php` (a file returning an array) or `--config=map.json`.

```php
<?php // migration-map.php
return [

    'users' => [
        'match_by'       => ['user_login', 'user_email'],
        'remap'          => [ 12 => 7, 34 => 7 ],   // collapse authors 12 & 34 into 7
        'role_map'       => [ 'contributor' => 'author' ],
        'on_missing'     => 'create',               // create | skip | assign_default
        'default_author' => 1,
    ],

    'terms' => [
        'match_by' => ['taxonomy', 'slug'],
    ],

    'posts' => [
        'match_by'   => ['post_type', 'post_name'], // or add 'guid'
        'status_map' => [],
    ],

    'attachments' => [
        'strategy' => 'sideload',                   // sideload | reference | map-existing | skip
    ],

    'meta' => [
        'post' => [
            'rename'  => [ 'old_seo_title' => '_yoast_wpseo_title' ],
            'drop'    => [ '_edit_lock', '_edit_last' ],
            'numeric' => [ '_thumbnail_id' ],
            'refs'    => [
                'hero_image'    => 'attachment',    // single attachment id
                'related_posts' => 'post[]',        // list of post ids
                'assigned_to'   => 'user',          // single user id
                'primary_cat'   => 'term',          // single term id
            ],
        ],
        'term' => [
            'rename' => [ 'legacy_color' => 'brand_color' ],
        ],
    ],

    'options' => [
        'mode'  => 'allowlist',                     // none | allowlist | all
        'allow' => [ 'blogname', 'blogdescription' ],
        'deny'  => [ 'cron', 'active_plugins', 'siteurl', 'home' ],
    ],
];
```

### Meta-key mapping

`meta.{type}` supports:

- `rename` — `{ old_key: new_key }`. If `new_key` already exists its values are
  merged.
- `drop` — keys to remove entirely.
- `numeric` — coerce numeric-string values to integers.
- `refs` — declare which keys hold references and of what type. Supported
  types: `post`, `post[]`, `attachment`, `attachment[]`, `term`, `term[]`,
  `user`, `user[]`. Declared refs are rewritten to destination ids in the
  rewrite phase. `_thumbnail_id` is treated as an attachment ref automatically.

### User mapping

`users.remap` redirects specific source authors to existing destination users.
`on_missing` sets the policy for users with no explicit remap:
`create` (default), `skip`, or `assign_default` (collapse to `default_author`).
`role_map` rewrites role names inside `wp_capabilities`.

### Attachment mapping

`attachments.strategy` selects one of the built-in strategies (or a strategy you
register). `sideload` (default) downloads the exported URL into the media
library; `reference` recreates the record but keeps the external URL;
`map-existing` matches by filename and falls back to `reference`; `skip` imports
nothing.

---

## 2. Filters

Every class seam has a matching filter, so you can tweak behaviour without
registering a class. All filters are guarded — they simply don't fire when
WordPress isn't loaded.

```php
// Redirect one author by rule.
add_filter('idempotent_import_map_user', function ($decision, $sourceUser, $ctx) {
    if (str_ends_with((string) $sourceUser['user_email'], '@old-agency.com')) {
        return \IdempotentImport\UserDecision::remap(1);
    }
    return $decision;
}, 10, 3);

// Rewrite a bespoke meta value (context is "post.meta.<key>").
add_filter('idempotent_import_rewrite_value', function ($value, $context, $idMap) {
    if ($context === 'post.meta.legacy_gallery' && is_array($value)) {
        return array_map(fn($id) => $idMap->post((int) $id) ?: $id, $value);
    }
    return $value;
}, 10, 3);

// Adjust post_content beyond the default block/URL rewriting.
add_filter('idempotent_import_rewrite_post_content', function ($html, $post, $idMap) {
    return str_replace('data-old-site', 'data-new-site', $html);
}, 10, 3);

// Choose an attachment strategy per file.
add_filter('idempotent_import_attachment_strategy', function ($name, $attachment, $ctx) {
    return str_contains($attachment['attachment_url'], '/videos/') ? 'reference' : $name;
}, 10, 3);
```

Lifecycle actions: `idempotent_import_before_entity`,
`idempotent_import_after_entity`, `idempotent_import_entity_skipped`.

Resolver filters: `idempotent_import_resolve_user|term|post|comment` receive the
default resolver's answer and may override it.

---

## 3. Registering classes

For heavier logic, register implementations on the `idempotent_import_register`
action, which fires after the defaults are loaded.

```php
add_action('idempotent_import_register', function ($registry, $ctx) {

    // Replace the default post resolver.
    $registry->registerResolver('post', new My\PostResolver());

    // A project-specific meta mapper.
    $registry->registerMetaMapper(new My\MetaMapper());

    // A custom attachment strategy, selectable via config strategy => 's3'.
    $registry->registerAttachmentStrategy('s3', new My\S3Strategy());

    // A reference rewriter for a custom block that stores ids.
    $registry->registerReferenceRewriter(new My\SliderRewriter());
});
```

### Interfaces

| Interface | Responsibility |
| --- | --- |
| `Contracts\Resolver` | Match an incoming entity to an existing destination id (or null to create). One per type. |
| `Contracts\UserMapper` | Decide reuse / create / remap / skip for a source user. |
| `Contracts\MetaMapper` | Reshape meta keys and coerce value types. |
| `Contracts\AttachmentStrategy` | Turn an exported attachment (URL only) into a destination attachment. |
| `Contracts\ReferenceRewriter` | `handles($context)` + `rewrite($value, $context)` for a meta/option value. |
| `Contracts\ContentRewriter` | Rewrite references embedded in `post_content`. |
| `Contracts\EntityImporter` | A whole new entity type: `createPhase()` + `rewritePhase()`. |

Each receives a `Context` carrying the WordPress gateway, `IdMap`, `Config`,
`Logger`, `Decoder`, `Manifest` and `Report`.

### Example: a reference rewriter for a custom block

```php
use IdempotentImport\Contracts\ReferenceRewriter;
use IdempotentImport\Context;

class SliderRewriter implements ReferenceRewriter {
    public function handles($context, Context $ctx) {
        return $context === 'post.meta._slider_slides';
    }
    public function rewrite($value, $context, Context $ctx) {
        // $value is one meta row: an array of ['image' => sourceAttachmentId].
        foreach ((array) $value as $i => $slide) {
            if (isset($slide['image'])) {
                $dest = $ctx->idMap->post((int) $slide['image']);
                if ($dest) { $value[$i]['image'] = $dest; }
            }
        }
        return $value;
    }
}
```

### Adding a whole entity type

Implement `Contracts\EntityImporter` (create + rewrite phases), then register it
and add it to the run — the same shape as the exporter's "drop a class under
`Exporter/`" extension point. This is the seam a future third-party-table
importer (HPOS, Gravity Forms) would use, matching the exporter's planned
third-party-table export hook.
