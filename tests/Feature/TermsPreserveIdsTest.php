<?php

declare(strict_types=1);

use IdempotentImport\ArrayLedger;
use IdempotentImport\Config;
use IdempotentImport\Tests\Support\FakeWordPress;
use IdempotentImport\Tests\Support\Harness;
use IdempotentImport\Tests\Support\SnapshotBuilder;

/**
 * Terms imported under their source term_id / term_taxonomy_id (spec 3.3.1),
 * which is what keeps `?cat={ID}` and the term IDs embedded in block attributes
 * and meta resolving after a 2U site migrates.
 */
function termPreserveIdsConfig(): Config
{
    return new Config([
        'terms'       => ['preserve_ids' => true],
        'posts'       => ['preserve_ids' => true],
        'attachments' => ['strategy' => 'reference'],
    ]);
}

function termPreserveIdsSnapshot(array $manifestSource = []): string
{
    $b = new SnapshotBuilder(tmpdir());
    $b->term(31, ['term_id' => 7, 'taxonomy' => 'degree_level', 'name' => 'Masters', 'slug' => 'masters', 'parent' => 0, 'count' => 4, 'term_group' => 3]);
    $b->term(32, ['term_id' => 9, 'taxonomy' => 'degree_level', 'name' => 'MSN', 'slug' => 'msn', 'parent' => 7, 'meta' => ['order' => ['2']]]);
    $b->manifest(array_merge(['auto_increment' => ['terms' => 900, 'term_taxonomy' => 950]], $manifestSource));
    return $b->dir();
}

it('inserts terms under their source term_id and term_taxonomy_id', function (): void {
    $wp  = new FakeWordPress();
    $ctx = Harness::run(termPreserveIdsSnapshot(), $wp, termPreserveIdsConfig());

    expect($wp->terms)->toHaveKey(7)
        ->and($wp->terms)->toHaveKey(9)
        ->and($wp->terms[9]['term_taxonomy_id'])->toBe(32)
        ->and($ctx->idMap->ttIdToTermId(32))->toBe(9)
        ->and($ctx->idMap->termId(9))->toBe(9);
});

it('carries term_group, count and description across verbatim', function (): void {
    $wp = new FakeWordPress();
    Harness::run(termPreserveIdsSnapshot(), $wp, termPreserveIdsConfig());

    expect($wp->terms[7]['term_group'])->toBe(3)
        ->and($wp->terms[7]['count'])->toBe(4);
});

it('sets the parent in the rewrite phase, never leaving a dangling ancestor', function (): void {
    $wp = new FakeWordPress();
    Harness::run(termPreserveIdsSnapshot(), $wp, termPreserveIdsConfig());

    // Inserted at parent 0, then set once every term row exists — otherwise anything
    // reading the hierarchy mid-run (Yoast's indexable builder calls get_term_link)
    // walks into an ancestor that has not been imported yet.
    expect($wp->terms[9]['parent'])->toBe(7)
        ->and($wp->updatedTermFields)->toBe([['id' => 9, 'fields' => ['parent' => 7]]]);
});

it('makes a post term assignment a no-op mapping onto the same ids', function (): void {
    $b = new SnapshotBuilder(tmpdir());
    $b->term(31, ['term_id' => 7, 'taxonomy' => 'degree_level', 'name' => 'Masters', 'slug' => 'masters']);
    $b->post(12345, [
        'post_type'   => 'plps',
        'post_title'  => 'Online MSN',
        'post_name'   => 'online-msn',
        'post_status' => 'publish',
        'terms'       => ['degree_level' => [31]],
    ]);
    $b->manifest(['auto_increment' => ['posts' => 20000, 'terms' => 900, 'term_taxonomy' => 950]]);

    $wp  = new FakeWordPress();
    $ctx = Harness::run($b->dir(), $wp, termPreserveIdsConfig());

    expect($wp->postTerms[12345]['degree_level'])->toBe([7])
        ->and($ctx->logger->warnCount())->toBe(0);
});

it('splits a term_id shared across taxonomies, keeping each ttid and warning', function (): void {
    // WordPress caches terms by term_id alone, so a shared term_id makes
    // term_exists($id, $taxonomy) answer with whichever taxonomy cached first and
    // wp_set_object_terms() write that taxonomy's ttid. Core 4.4 splits these; so do we.
    $b = new SnapshotBuilder(tmpdir());
    $b->term(31, ['term_id' => 7, 'taxonomy' => 'category', 'name' => 'Shared', 'slug' => 'shared']);
    $b->term(32, ['term_id' => 7, 'taxonomy' => 'post_tag', 'name' => 'Shared', 'slug' => 'shared']);
    $b->term(33, ['term_id' => 9, 'taxonomy' => 'category', 'name' => 'Kid', 'slug' => 'kid', 'parent' => 7]);
    $b->manifest(['auto_increment' => ['terms' => 900, 'term_taxonomy' => 950]]);

    $wp  = new FakeWordPress();
    $ctx = Harness::run($b->dir(), $wp, termPreserveIdsConfig());

    $categoryTermId = $ctx->idMap->ttIdToTermId(31);
    $tagTermId      = $ctx->idMap->ttIdToTermId(32);

    expect($categoryTermId)->toBe(7)                 // first taxonomy keeps the source id
        ->and($tagTermId)->not->toBe(7)              // second is split onto its own
        ->and($wp->terms[$tagTermId]['term_taxonomy_id'])->toBe(32)  // ttid still preserved
        ->and($ctx->idMap->termId(7))->toBe(7)       // `parent` resolves to the first
        ->and($wp->terms[9]['parent'])->toBe(7)
        ->and($ctx->logger->warnCount())->toBe(1);
});

it('raises both term tables auto_increment past the snapshot range', function (): void {
    $wp = new FakeWordPress();
    Harness::run(termPreserveIdsSnapshot(), $wp, termPreserveIdsConfig());

    // Proven through the next id the fake hands out.
    $next = $wp->insertTerm('After migration', 'category', ['slug' => 'after-migration']);
    expect($next['term_id'])->toBe(900)
        ->and($next['term_taxonomy_id'])->toBe(950);
});

it('falls back to the highest imported ttid when the manifest predates term_taxonomy', function (): void {
    $wp = new FakeWordPress();
    Harness::run(termPreserveIdsSnapshot(['auto_increment' => ['terms' => 900]]), $wp, termPreserveIdsConfig());

    // Snapshot ttids are 31 and 32, so the next free one is 33.
    expect($wp->termsAutoIncrement)->toBe([['terms' => 900, 'term_taxonomy' => 33]]);
});

it('leaves the term auto_increment alone when ids are reissued', function (): void {
    $wp = new FakeWordPress();
    Harness::run(termPreserveIdsSnapshot(), $wp, new Config());

    expect($wp->termsAutoIncrement)->toBe([]);
});

it('refuses to reissue when the destination term_taxonomy_id is occupied', function (): void {
    $b = new SnapshotBuilder(tmpdir());
    $b->term(31, ['term_id' => 7, 'taxonomy' => 'degree_level', 'name' => 'Masters', 'slug' => 'masters']);
    $b->manifest(['auto_increment' => ['terms' => 900, 'term_taxonomy' => 950]]);

    $wp = new FakeWordPress();
    // Unrelated seed content already sitting on ttid 31.
    $wp->insertTermWithIds(2, 31, 'Leftover', 'category', ['slug' => 'leftover']);

    $ctx = Harness::run($b->dir(), $wp, termPreserveIdsConfig());

    expect($ctx->report->outcomes()['term']['skipped'])->toBe(1)
        ->and($ctx->logger->skipCount())->toBe(1)
        ->and($wp->terms)->not->toHaveKey(7);
});

it('refuses to reissue when the destination term_id holds a different term', function (): void {
    $b = new SnapshotBuilder(tmpdir());
    $b->term(31, ['term_id' => 7, 'taxonomy' => 'degree_level', 'name' => 'Masters', 'slug' => 'masters']);
    $b->manifest(['auto_increment' => ['terms' => 900, 'term_taxonomy' => 950]]);

    $wp = new FakeWordPress();
    $wp->insertTermWithIds(7, 99, 'Leftover', 'category', ['slug' => 'leftover']);

    $ctx = Harness::run($b->dir(), $wp, termPreserveIdsConfig());

    expect($ctx->report->outcomes()['term']['skipped'])->toBe(1)
        ->and($wp->terms[7]['slug'])->toBe('leftover');
});

it('reports a skip when a matched destination term does not hold the source ids', function (): void {
    $b = new SnapshotBuilder(tmpdir());
    $b->term(31, ['term_id' => 7, 'taxonomy' => 'category', 'name' => 'Uncategorized', 'slug' => 'uncategorized']);
    $b->manifest(['auto_increment' => ['terms' => 900, 'term_taxonomy' => 950]]);

    $wp = new FakeWordPress();
    // The default category `wp site empty` re-seeds, at term_id 1 rather than 7.
    $wp->insertTermWithIds(1, 1, 'Uncategorized', 'category', ['slug' => 'uncategorized']);

    $ctx = Harness::run($b->dir(), $wp, termPreserveIdsConfig());

    expect($ctx->report->outcomes()['term']['skipped'])->toBe(1)
        ->and($ctx->logger->skipCount())->toBe(1);
});

it('adopts the destination term already sitting on the source ids', function (): void {
    $b = new SnapshotBuilder(tmpdir());
    $b->term(31, ['term_id' => 7, 'taxonomy' => 'category', 'name' => 'Masters', 'slug' => 'masters', 'meta' => ['order' => ['2']]]);
    $b->manifest(['auto_increment' => ['terms' => 900, 'term_taxonomy' => 950]]);

    $wp = new FakeWordPress();
    $wp->insertTermWithIds(7, 31, 'Masters', 'category', ['slug' => 'masters']);

    $ctx = Harness::run($b->dir(), $wp, termPreserveIdsConfig());

    expect($ctx->report->outcomes()['term']['matched'])->toBe(1)
        ->and($ctx->logger->skipCount())->toBe(0)
        ->and($wp->termMeta[7]['order'][0])->toBe('2');
});

it('is idempotent: a second run changes nothing', function (): void {
    $dir    = termPreserveIdsSnapshot();
    $wp     = new FakeWordPress();
    $ledger = new ArrayLedger();

    Harness::run($dir, $wp, termPreserveIdsConfig(), 'update', $ledger);
    $before = $wp->terms;

    $ctx = Harness::run($dir, $wp, termPreserveIdsConfig(), 'update', $ledger);

    expect($ctx->report->outcomes()['term']['unchanged'])->toBe(2)
        ->and($ctx->report->outcomes()['term']['created'])->toBe(0)
        ->and($wp->terms)->toBe($before)
        ->and($ctx->logger->skipCount())->toBe(0);
});
