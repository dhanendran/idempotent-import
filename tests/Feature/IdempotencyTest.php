<?php

declare(strict_types=1);

use IdempotentImport\ArrayLedger;
use IdempotentImport\Tests\Support\FakeWordPress;
use IdempotentImport\Tests\Support\Harness;
use IdempotentImport\Tests\Support\SnapshotBuilder;

function fullSnapshot(): string
{
    $b = new SnapshotBuilder(tmpdir());
    $b->user(42, ['user_login' => 'alice', 'user_email' => 'a@example.com', 'meta' => ['nickname' => ['alice']]]);
    $b->term(10, ['term_id' => 4, 'taxonomy' => 'category', 'name' => 'News', 'slug' => 'news']);
    $b->post(12345, [
        'post_name'   => 'hello',
        'post_author' => 42,
        'terms'       => ['category' => [10]],
        'meta'        => ['k' => ['v']],
    ]);
    $b->comment(991, ['comment_post_ID' => 12345, 'comment_author_email' => 'bob@example.com', 'comment_content' => 'Nice']);
    $b->options(['blogname' => ['autoload' => 'yes', 'value' => 'Site']]);
    $b->manifest();
    return $b->dir();
}

it('produces the same destination state when run twice', function (): void {
    $wp     = new FakeWordPress();
    $ledger = new ArrayLedger();
    $dir    = fullSnapshot();

    Harness::run($dir, $wp, null, 'update', $ledger);
    $snapshot1 = [
        'users'    => count($wp->users),
        'terms'    => count($wp->terms),
        'posts'    => count($wp->posts),
        'comments' => count($wp->comments),
    ];

    $ctx2 = Harness::run($dir, $wp, null, 'update', $ledger);
    $snapshot2 = [
        'users'    => count($wp->users),
        'terms'    => count($wp->terms),
        'posts'    => count($wp->posts),
        'comments' => count($wp->comments),
    ];

    expect($snapshot2)->toBe($snapshot1);

    // Second run should classify everything as matched, not created.
    $outcomes = $ctx2->report->outcomes();
    expect($outcomes['user']['created'] ?? 0)->toBe(0)
        ->and($outcomes['post']['created'] ?? 0)->toBe(0)
        ->and($outcomes['comment']['created'] ?? 0)->toBe(0);
});
