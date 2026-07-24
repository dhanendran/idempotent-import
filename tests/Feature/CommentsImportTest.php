<?php

declare(strict_types=1);

use IdempotentImport\Tests\Support\FakeWordPress;
use IdempotentImport\Tests\Support\Harness;
use IdempotentImport\Tests\Support\SnapshotBuilder;

function commentsSnapshot(): string
{
    $b = new SnapshotBuilder(tmpdir());
    $b->user(42, ['user_login' => 'alice', 'user_email' => 'a@example.com']);
    $b->post(12345, ['post_name' => 'hello', 'post_author' => 42]);
    $b->comment(991, [
        'comment_post_ID'      => 12345,
        'comment_author'       => 'Bob',
        'comment_author_email' => 'bob@example.com',
        'comment_content'      => 'Nice',
        'comment_parent'       => 0,
        'user_id'              => 42,
        'meta'                 => ['rating' => ['5']],
    ]);
    $b->comment(992, [
        'comment_post_ID' => 12345,
        'comment_author'  => 'Cara',
        'comment_content' => 'Agreed',
        'comment_parent'  => 991,
        'user_id'         => 0,
    ]);
    $b->manifest();
    return $b->dir();
}

it('maps comment post and author, and threads the parent', function (): void {
    $wp  = new FakeWordPress();
    $ctx = Harness::run(commentsSnapshot(), $wp);

    $destPost   = $ctx->idMap->post(12345);
    $destParent = $ctx->idMap->comment(991);
    $destChild  = $ctx->idMap->comment(992);

    expect($wp->comments[$destParent]['comment_post_ID'])->toBe($destPost)
        ->and($wp->comments[$destParent]['user_id'])->toBe($ctx->idMap->user(42))
        ->and($wp->comments[$destChild]['comment_parent'])->toBe($destParent)
        ->and($wp->commentMeta[$destParent]['rating'][0])->toBe('5');
});

it('skips a comment whose post is missing', function (): void {
    $b = new SnapshotBuilder(tmpdir());
    $b->comment(700, ['comment_post_ID' => 99999, 'comment_content' => 'orphan']);
    $b->manifest();

    $wp  = new FakeWordPress();
    $ctx = Harness::run($b->dir(), $wp);
    expect($wp->comments)->toHaveCount(0)
        ->and($ctx->logger->skipCount())->toBe(1);
});
