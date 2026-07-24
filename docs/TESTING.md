# Testing

The suite is [Pest](https://pestphp.com/) on top of PHPUnit, mirroring the
exporter's setup.

## Running

```
composer install
composer test            # all suites
composer test:unit       # Unit only
composer test:feature    # Feature only
```

PHP 8.1+ is required for the test suite (the runtime itself supports 7.4+).

## Layout

```
tests/
├── bootstrap.php            production + test autoloaders
├── Pest.php                 afterEach cleanup, tmpdir() helper
├── Support/
│   ├── Env.php              temp-dir bookkeeping
│   ├── FakeWordPress.php    in-memory WordPress gateway
│   ├── SnapshotBuilder.php  writes a snapshot tree in the exporter's layout
│   └── Harness.php          runs the two-phase import against the fake
├── Unit/                    pure-logic tests (no I/O beyond tmp files)
└── Feature/                 end-to-end import tests
```

## The test double

The importer talks to WordPress only through the `WordPress` gateway interface,
so tests inject `FakeWordPress` — an in-memory implementation that records every
insert, meta write, term assignment and option update. No live WordPress, no
database, no `$wpdb` shims. This is the importer's analogue of the exporter's
`FakeWpdb`.

`Harness::run($dir, $fakeWp, $config, $onConflict, $ledger)` wires a `Context`
with an `ArrayLedger` and the default `Registry`, then executes the create phase
followed by the rewrite phase — exactly what `Run` does, minus the WP-CLI and
`$wpdb` dependencies. It returns the `Context` so tests can assert on
`$ctx->idMap`, the fake gateway's recorded state, and the `Report`.

`SnapshotBuilder` writes a valid snapshot (manifest, users, terms, posts,
comments, options) to a temp directory, so feature tests read through the real
`Snapshot` reader.

## What's covered

- **Unit** — deterministic JSON, slash round-tripping, config merge/rules, the
  manifest source key and compatibility check, the id map, the core reference
  rewriter (implicit + declared refs), the meta mapper (rename/drop/numeric/
  role_map), and the block/URL content rewriter.
- **Feature** — users (create, reuse, remap, idempotent re-run), terms
  (hierarchy + three-way id mapping), posts (author remap, term assignment,
  `_thumbnail_id` and content rewriting), comments (post/user mapping and
  threading, orphan skip), options (allowlist policy and reference remap),
  attachments (sideload / reference / skip), and full-snapshot **idempotency**
  (importing twice yields the same destination state with no new entities).

## Writing a new test

```php
use IdempotentImport\Tests\Support\{FakeWordPress, Harness, SnapshotBuilder};

it('does the thing', function (): void {
    $b = new SnapshotBuilder(tmpdir());
    $b->post(1, ['post_name' => 'x', 'meta' => ['k' => ['v']]]);
    $b->manifest();

    $wp  = new FakeWordPress();
    $ctx = Harness::run($b->dir(), $wp);

    expect($ctx->idMap->post(1))->not->toBeNull();
});
```
