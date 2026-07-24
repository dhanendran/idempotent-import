<?php

declare(strict_types=1);

use IdempotentImport\Config;
use IdempotentImport\Rewriter\CoreReferenceRewriter;
use IdempotentImport\Tests\Support\Harness;

it('rewrites the implicit _thumbnail_id attachment reference', function (): void {
    $ctx = Harness::context();
    $ctx->idMap->rememberPost(8821, 9001); // source attachment -> dest

    $rw = new CoreReferenceRewriter();
    expect($rw->handles('post.meta._thumbnail_id', $ctx))->toBeTrue();
    expect($rw->rewrite('8821', 'post.meta._thumbnail_id', $ctx))->toBe('9001');
});

it('leaves unmapped references unchanged', function (): void {
    $ctx = Harness::context();
    $rw  = new CoreReferenceRewriter();
    expect($rw->rewrite('7777', 'post.meta._thumbnail_id', $ctx))->toBe('7777');
});

it('rewrites config-declared scalar and list references', function (): void {
    $config = new Config(['meta' => ['post' => ['refs' => [
        'hero'    => 'attachment',
        'related' => 'post[]',
        'editor'  => 'user',
    ]]]]);
    $ctx = Harness::context(null, $config);
    $ctx->idMap->rememberPost(201, 1201);
    $ctx->idMap->rememberPost(305, 1305);
    $ctx->idMap->rememberUser(42, 100);

    $rw = new CoreReferenceRewriter();
    expect($rw->rewrite('201', 'post.meta.hero', $ctx))->toBe('1201');
    expect($rw->rewrite([201, 305], 'post.meta.related', $ctx))->toBe([1201, 1305]);
    expect($rw->rewrite('42', 'post.meta.editor', $ctx))->toBe('100');
});

it('does not handle non-reference keys', function (): void {
    $ctx = Harness::context();
    $rw  = new CoreReferenceRewriter();
    expect($rw->handles('post.meta.some_text', $ctx))->toBeFalse();
});
