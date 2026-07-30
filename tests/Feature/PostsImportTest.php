<?php

declare(strict_types=1);

use IdempotentImport\ArrayLedger;
use IdempotentImport\Tests\Support\FakeWordPress;
use IdempotentImport\Tests\Support\Harness;
use IdempotentImport\Tests\Support\SnapshotBuilder;

function postsSnapshot(): string
{
    $b = new SnapshotBuilder(tmpdir());
    $b->user(42, ['user_login' => 'alice', 'user_email' => 'a@example.com']);
    $b->term(10, ['term_id' => 4, 'taxonomy' => 'category', 'name' => 'News', 'slug' => 'news']);
    $b->post(8821, [
        'post_type'      => 'attachment',
        'post_title'     => 'Photo',
        'attachment_url' => 'https://source.test/wp-content/uploads/photo.jpg',
        'meta'           => ['_wp_attached_file' => ['2024/03/photo.jpg']],
    ]);
    $b->post(12345, [
        'post_type'    => 'post',
        'post_title'   => 'Hello',
        'post_name'    => 'hello',
        'post_author'  => 42,
        'post_content' => '<!-- wp:image {"id":8821} --><img class="wp-image-8821" src="https://source.test/wp-content/uploads/photo.jpg"/><!-- /wp:image -->',
        'terms'        => ['category' => [10]],
        'meta'         => ['_thumbnail_id' => ['8821'], 'plain' => ['value']],
    ]);
    $b->manifest();
    return $b->dir();
}

it('creates the post with a remapped author', function (): void {
    $wp  = new FakeWordPress();
    $ctx = Harness::run(postsSnapshot(), $wp);

    $destPost = $ctx->idMap->post(12345);
    expect($destPost)->not->toBeNull()
        ->and($wp->posts[$destPost]['post_author'])->toBe($ctx->idMap->user(42));
});

it('assigns terms by destination term_id', function (): void {
    $wp  = new FakeWordPress();
    $ctx = Harness::run(postsSnapshot(), $wp);

    $destPost = $ctx->idMap->post(12345);
    expect($wp->postTerms[$destPost]['category'])->toBe([$ctx->idMap->ttIdToTermId(10)]);
});

it('warns rather than silently dropping an assignment whose term was never imported', function (): void {
    // A run that leaves terms out (--only=users,posts) can reconcile clean while
    // having lost every taxonomy assignment on the site, so this has to be loud.
    $b = new SnapshotBuilder(tmpdir());
    $b->post(12345, [
        'post_type'   => 'plps',
        'post_title'  => 'Programme',
        'post_name'   => 'programme',
        'post_status' => 'publish',
        'terms'       => ['degree_level' => [22, 23]],
    ]);
    $b->manifest();

    $wp  = new FakeWordPress();
    $ctx = Harness::run($b->dir(), $wp, null, 'update', new ArrayLedger());

    $destPost = $ctx->idMap->post(12345);
    expect($ctx->logger->warnCount())->toBe(1)
        ->and($wp->postTerms[$destPost] ?? [])->toBe([]);
});

it('rewrites _thumbnail_id to the destination attachment id', function (): void {
    $wp  = new FakeWordPress();
    $ctx = Harness::run(postsSnapshot(), $wp);

    $destPost   = $ctx->idMap->post(12345);
    $destAttach = $ctx->idMap->post(8821);
    expect($wp->postMeta[$destPost]['_thumbnail_id'][0])->toBe((string) $destAttach)
        ->and($wp->postMeta[$destPost]['plain'][0])->toBe('value');
});

it('rewrites block id, image class and attachment url in content', function (): void {
    $wp  = new FakeWordPress();
    $ctx = Harness::run(postsSnapshot(), $wp);

    $destPost   = $ctx->idMap->post(12345);
    $destAttach = $ctx->idMap->post(8821);
    $content    = $wp->posts[$destPost]['post_content'];

    expect($content)->toContain('"id":' . $destAttach)
        ->and($content)->toContain('wp-image-' . $destAttach)
        ->and($content)->not->toContain('8821')
        ->and($content)->toContain('dest.test'); // url remapped via sideload
});

it('is idempotent for posts', function (): void {
    $wp     = new FakeWordPress();
    $ledger = new ArrayLedger();
    $dir    = postsSnapshot();

    Harness::run($dir, $wp, null, 'update', $ledger);
    $afterFirst = count($wp->posts);
    Harness::run($dir, $wp, null, 'update', $ledger);

    expect(count($wp->posts))->toBe($afterFirst);
});
