<?php

declare(strict_types=1);

use IdempotentImport\ArrayLedger;
use IdempotentImport\Tests\Support\FakeWordPress;
use IdempotentImport\Tests\Support\Harness;
use IdempotentImport\Tests\Support\SnapshotBuilder;

function termsSnapshot(): string
{
    $b = new SnapshotBuilder(tmpdir());
    $b->term(10, ['term_id' => 4, 'taxonomy' => 'category', 'name' => 'Engineering', 'slug' => 'engineering', 'parent' => 0]);
    $b->term(17, ['term_id' => 8, 'taxonomy' => 'category', 'name' => 'Backend', 'slug' => 'backend', 'parent' => 4, 'meta' => ['order' => ['3']]]);
    $b->manifest();
    return $b->dir();
}

it('creates terms and records all three mappings', function (): void {
    $wp  = new FakeWordPress();
    $ctx = Harness::run(termsSnapshot(), $wp);

    expect($wp->terms)->toHaveCount(2)
        ->and($ctx->idMap->term(17))->not->toBeNull()
        ->and($ctx->idMap->termId(8))->not->toBeNull()
        ->and($ctx->idMap->ttIdToTermId(17))->toBe($ctx->idMap->termId(8));
});

it('sets the child parent to the destination parent term_id', function (): void {
    $wp  = new FakeWordPress();
    $ctx = Harness::run(termsSnapshot(), $wp);

    $childTermId  = $ctx->idMap->ttIdToTermId(17);
    $parentTermId = $ctx->idMap->termId(4);
    expect($wp->terms[$childTermId]['parent'])->toBe($parentTermId);
});

it('writes term meta', function (): void {
    $wp  = new FakeWordPress();
    $ctx = Harness::run(termsSnapshot(), $wp);
    $childTermId = $ctx->idMap->ttIdToTermId(17);
    expect($wp->termMeta[$childTermId]['order'][0])->toBe('3');
});

it('re-syncs a term whose source changed, so a delta re-import keeps term edits', function (): void {
    $dir    = tmpdir();
    $wp     = new FakeWordPress();
    $ledger = new ArrayLedger();

    $b = new SnapshotBuilder($dir);
    $b->term(10, ['term_id' => 4, 'taxonomy' => 'category', 'name' => 'Engineering', 'slug' => 'engineering', 'description' => 'first']);
    $b->manifest();
    Harness::run($dir, $wp, null, 'update', $ledger);

    // The source is edited between the dry cutover and the delta run.
    $b->term(10, [
        'term_id'     => 4,
        'taxonomy'    => 'category',
        'name'        => 'Engineering & Tech',
        'slug'        => 'engineering',
        'description' => 'second',
        'meta'        => ['seo_title' => ['Eng']],
    ]);
    $b->manifest();
    $ctx = Harness::run($dir, $wp, null, 'update', $ledger);

    $termId = $ctx->idMap->ttIdToTermId(10);
    expect($ctx->report->outcomes()['term']['updated'])->toBe(1)
        ->and($wp->terms[$termId]['name'])->toBe('Engineering & Tech')
        ->and($wp->terms[$termId]['description'])->toBe('second')
        ->and($wp->termMeta[$termId]['seo_title'][0])->toBe('Eng');
});

it('keeps a changed term as-is when --on-conflict is not update', function (): void {
    $dir    = tmpdir();
    $wp     = new FakeWordPress();
    $ledger = new ArrayLedger();

    $b = new SnapshotBuilder($dir);
    $b->term(10, ['term_id' => 4, 'taxonomy' => 'category', 'name' => 'Engineering', 'slug' => 'engineering']);
    $b->manifest();
    Harness::run($dir, $wp, null, 'skip', $ledger);

    $b->term(10, ['term_id' => 4, 'taxonomy' => 'category', 'name' => 'Renamed', 'slug' => 'engineering']);
    $b->manifest();
    $ctx = Harness::run($dir, $wp, null, 'skip', $ledger);

    $termId = $ctx->idMap->ttIdToTermId(10);
    expect($ctx->report->outcomes()['term']['conflict'])->toBe(1)
        ->and($wp->terms[$termId]['name'])->toBe('Engineering');
});

it('writes hierarchy and meta onto a term matched to pre-existing destination content', function (): void {
    $b = new SnapshotBuilder(tmpdir());
    $b->term(10, ['term_id' => 4, 'taxonomy' => 'category', 'name' => 'Parent', 'slug' => 'parent-cat', 'parent' => 0]);
    $b->term(17, ['term_id' => 8, 'taxonomy' => 'category', 'name' => 'Child', 'slug' => 'child-cat', 'parent' => 4, 'meta' => ['order' => ['3']]]);
    $b->manifest();

    $wp = new FakeWordPress();
    // What `wp site empty` leaves behind: it truncates the term tables and then
    // re-inserts a default category, so a same-slug destination term is the norm.
    $wp->insertTerm('Child', 'category', ['slug' => 'child-cat']);

    $ctx = Harness::run($b->dir(), $wp, null, 'update', new ArrayLedger());

    $childId = $ctx->idMap->ttIdToTermId(17);
    expect($ctx->report->outcomes()['term']['matched'])->toBe(1)
        ->and($wp->terms[$childId]['parent'])->toBe($ctx->idMap->termId(4))
        ->and($wp->termMeta[$childId]['order'][0])->toBe('3');
});

it('warns when a source term_id shared across taxonomies would be split', function (): void {
    $b = new SnapshotBuilder(tmpdir());
    $b->term(10, ['term_id' => 4, 'taxonomy' => 'category', 'name' => 'Shared', 'slug' => 'shared']);
    $b->term(11, ['term_id' => 4, 'taxonomy' => 'post_tag', 'name' => 'Shared', 'slug' => 'shared']);
    $b->manifest();

    $wp  = new FakeWordPress();
    $ctx = Harness::run($b->dir(), $wp, null, 'update', new ArrayLedger());

    expect($ctx->logger->warnCount())->toBe(1)
        ->and($ctx->idMap->termId(4))->toBe($ctx->idMap->ttIdToTermId(10));
});

it('skips a term whose taxonomy the destination does not register', function (): void {
    // Measured on the real legacy instance: it registers `policy_type`, the new one
    // registers `degree_level` and friends. A preserved-ID insert writes rows directly
    // and would bypass wp_insert_term()'s check, leaving the run at zero skips and
    // exit 0 while every post assignment failed as a warning.
    $b = new SnapshotBuilder(tmpdir());
    $b->term(31, ['term_id' => 7, 'taxonomy' => 'policy_type', 'name' => 'Terms of Use', 'slug' => 'tou']);
    $b->manifest(['auto_increment' => ['terms' => 900, 'term_taxonomy' => 950]]);

    $wp = new FakeWordPress();
    $wp->registeredTaxonomies = ['category', 'post_tag', 'degree_level'];

    $ctx = Harness::run($b->dir(), $wp, new \IdempotentImport\Config(['terms' => ['preserve_ids' => true]]), 'update', new ArrayLedger());

    expect($ctx->report->outcomes()['term']['skipped'])->toBe(1)
        ->and($ctx->logger->skipCount())->toBe(1)
        ->and($wp->terms)->toBe([]);
});
