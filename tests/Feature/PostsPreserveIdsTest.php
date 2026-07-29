<?php

declare(strict_types=1);

use IdempotentImport\ArrayLedger;
use IdempotentImport\Config;
use IdempotentImport\Tests\Support\FakeWordPress;
use IdempotentImport\Tests\Support\Harness;
use IdempotentImport\Tests\Support\SnapshotBuilder;

/**
 * Posts imported under their source IDs (spec 3.3.1-3.3.3), which is what keeps
 * `?p={ID}` and ID-bearing references resolving after a 2U site migrates.
 */
function preserveIdsConfig(): Config
{
    return new Config([
        'posts'       => ['preserve_ids' => true],
        'attachments' => ['strategy' => 'reference'],
    ]);
}

function preserveIdsSnapshot(array $postOverrides = [], int $autoIncrement = 20000): string
{
    $b = new SnapshotBuilder(tmpdir());
    $b->post(8821, [
        'post_type'      => 'attachment',
        'post_title'     => 'Photo',
        'post_name'      => 'photo',
        'attachment_url' => 'https://source.test/wp-content/uploads/2024/03/photo.jpg',
        'meta'           => [
            '_wp_attached_file'         => ['2024/03/photo.jpg'],
            '_wp_attachment_image_alt'  => ['A lecture hall'],
        ],
    ]);
    $b->post(12345, array_merge([
        'post_type'    => 'plps',
        'post_title'   => 'Online BSN',
        'post_name'    => 'online-bsn',
        'post_status'  => 'publish',
        'post_content' => '<p>Programme overview</p>',
        'meta'         => ['_thumbnail_id' => ['8821'], 'footer_uni_short' => ['Nursing']],
    ], $postOverrides));
    $b->manifest(['auto_increment' => ['posts' => $autoIncrement]]);
    return $b->dir();
}

it('inserts posts and attachments under their source ids', function (): void {
    $wp = new FakeWordPress();
    Harness::run(preserveIdsSnapshot(), $wp, preserveIdsConfig());

    expect($wp->posts)->toHaveKey(12345)
        ->and($wp->posts)->toHaveKey(8821)
        ->and($wp->posts[12345]['post_title'])->toBe('Online BSN');
});

it('keeps _thumbnail_id pointing at the same id, so the reference is a no-op', function (): void {
    $wp = new FakeWordPress();
    Harness::run(preserveIdsSnapshot(), $wp, preserveIdsConfig());

    expect($wp->postMeta[12345]['_thumbnail_id'][0])->toBe('8821');
});

it('refuses to reissue an id when the destination one is occupied by unrelated content', function (): void {
    $wp = new FakeWordPress();
    // Leftover seed content sitting on an id the snapshot needs.
    $wp->posts[12345] = ['post_type' => 'page', 'post_name' => 'sample-page'];

    $ctx = Harness::run(preserveIdsSnapshot(), $wp, preserveIdsConfig());

    expect($ctx->report->outcomes()['post']['skipped'] ?? 0)->toBe(1)
        ->and($wp->posts[12345])->toBe(['post_type' => 'page', 'post_name' => 'sample-page'])
        ->and($ctx->idMap->post(12345))->toBeNull();
});

it('adopts the row already at the source id when type and slug corroborate', function (): void {
    $wp = new FakeWordPress();
    $wp->posts[12345] = ['post_type' => 'plps', 'post_name' => 'online-bsn'];

    $ctx = Harness::run(preserveIdsSnapshot(), $wp, preserveIdsConfig());

    expect($ctx->idMap->post(12345))->toBe(12345)
        ->and($ctx->report->outcomes()['post']['skipped'] ?? 0)->toBe(0);
});

it('raises the posts auto_increment past the snapshot range', function (): void {
    $wp = new FakeWordPress();
    Harness::run(preserveIdsSnapshot(), $wp, preserveIdsConfig());

    $next = $wp->insertPost(['post_type' => 'post', 'post_title' => 'Written after migration']);
    expect($next)->toBeGreaterThanOrEqual(20000);
});

it('leaves auto_increment alone when ids are reissued', function (): void {
    $wp = new FakeWordPress();
    Harness::run(preserveIdsSnapshot(), $wp);

    $next = $wp->insertPost(['post_type' => 'post', 'post_title' => 'Written after migration']);
    expect($next)->toBeLessThan(20000);
});

it('re-syncs a changed post\'s own columns on a delta re-import', function (): void {
    $wp     = new FakeWordPress();
    $ledger = new ArrayLedger();
    $config = preserveIdsConfig();

    Harness::run(preserveIdsSnapshot(), $wp, $config, 'update', $ledger);
    expect($wp->posts[12345]['post_title'])->toBe('Online BSN');

    // Post-freeze re-export: the editor retitled and unpublished the page.
    $changed = preserveIdsSnapshot([
        'post_title'  => 'Online BSN (2027 intake)',
        'post_status' => 'draft',
    ]);
    Harness::run($changed, $wp, $config, 'update', $ledger);

    expect($wp->posts[12345]['post_title'])->toBe('Online BSN (2027 intake)')
        ->and($wp->posts[12345]['post_status'])->toBe('draft')
        ->and(count($wp->posts))->toBe(2); // No duplicate row.
});

it('imports attachment alt text', function (): void {
    $wp = new FakeWordPress();
    Harness::run(preserveIdsSnapshot(), $wp, preserveIdsConfig());

    expect($wp->postMeta[8821]['_wp_attachment_image_alt'][0])->toBe('A lecture hall')
        ->and($wp->postMeta[8821]['_wp_attached_file'][0])->toBe('2024/03/photo.jpg');
});

it('sets post_parent at insert so sibling slugs are scoped, not renamed', function (): void {
    $wp = new FakeWordPress();
    $b  = new SnapshotBuilder(tmpdir());
    $b->post(100, ['post_type' => 'page', 'post_name' => 'nursing', 'post_parent' => 0]);
    $b->post(200, ['post_type' => 'page', 'post_name' => 'business', 'post_parent' => 0]);
    // Two pages legitimately sharing a slug under different parents.
    $b->post(101, ['post_type' => 'page', 'post_name' => 'tuition', 'post_parent' => 100]);
    $b->post(201, ['post_type' => 'page', 'post_name' => 'tuition', 'post_parent' => 200]);
    $b->manifest(['auto_increment' => ['posts' => 300]]);

    Harness::run($b->dir(), $wp, preserveIdsConfig());

    // The parent must be on the row at insert time — that is what scopes the slug.
    expect($wp->posts[101]['post_parent'])->toBe(100)
        ->and($wp->posts[201]['post_parent'])->toBe(200)
        ->and($wp->posts[101]['post_name'])->toBe('tuition')
        ->and($wp->posts[201]['post_name'])->toBe('tuition');
});

it('reports referenced attachments as created, so counts reconcile', function (): void {
    $wp  = new FakeWordPress();
    $ctx = Harness::run(preserveIdsSnapshot(), $wp, preserveIdsConfig());

    $attachments = $ctx->report->outcomes()['attachment'];
    expect($attachments['created'])->toBe(1)
        ->and($attachments)->not->toHaveKey('referenced');
});

it('refuses an attachment whose id is occupied, rather than drifting its url', function (): void {
    $wp = new FakeWordPress();
    $wp->posts[8821] = ['post_type' => 'attachment', 'post_name' => 'someone-elses-photo'];

    $ctx = Harness::run(preserveIdsSnapshot(), $wp, preserveIdsConfig());

    expect($ctx->report->outcomes()['attachment']['skipped'] ?? 0)->toBe(1)
        ->and($ctx->idMap->post(8821))->toBeNull()
        ->and($wp->posts[8821]['post_name'])->toBe('someone-elses-photo');
});

it('reports a skip when a preserved attachment id is refused by the destination', function (): void {
    $wp = new FakeWordPress();
    // sideload cannot honour import_id, so the id it returns is not the source id.
    $config = new Config([
        'posts'       => ['preserve_ids' => true],
        'attachments' => ['strategy' => 'sideload'],
    ]);

    $ctx = Harness::run(preserveIdsSnapshot(), $wp, $config);

    expect($ctx->report->outcomes()['attachment']['skipped'] ?? 0)->toBe(1)
        ->and($ctx->idMap->post(8821))->toBeNull();
});

it('withholds binary-derived meta when the destination sideloaded its own file', function (): void {
    $wp  = new FakeWordPress();
    $ctx = Harness::run(preserveIdsSnapshot(), $wp); // Default sideload strategy.

    $destAttach = $ctx->idMap->post(8821);
    expect($wp->postMeta[$destAttach]['_wp_attachment_image_alt'][0])->toBe('A lecture hall')
        ->and($wp->postMeta[$destAttach]['_wp_attached_file'][0])->toBe('photo.jpg');
});
