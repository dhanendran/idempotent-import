<?php

declare(strict_types=1);

use IdempotentImport\Rewriter\DefaultContentRewriter;
use IdempotentImport\Tests\Support\Harness;

it('rewrites block ids, image classes and urls', function (): void {
    $ctx = Harness::context();
    $ctx->idMap->rememberPost(123, 999);
    $ctx->idMap->rememberUrl('https://src.test/a.jpg', 'https://dst.test/a.jpg');

    $html = '<!-- wp:image {"id":123,"sizeSlug":"large"} -->'
        . '<figure class="wp-block-image size-large">'
        . '<img src="https://src.test/a.jpg" class="wp-image-123"/></figure>'
        . '<!-- /wp:image -->';

    $out = (new DefaultContentRewriter())->rewrite($html, [], $ctx);

    expect($out)->toContain('"id":999')
        ->and($out)->toContain('wp-image-999')
        ->and($out)->toContain('https://dst.test/a.jpg')
        ->and($out)->not->toContain('wp-image-123')
        ->and($out)->not->toContain('https://src.test/a.jpg');
});

it('leaves unknown ids and urls intact', function (): void {
    $ctx  = Harness::context();
    $html = '<!-- wp:image {"id":555} --><img class="wp-image-555"/>';
    expect((new DefaultContentRewriter())->rewrite($html, [], $ctx))->toBe($html);
});

it('returns empty content untouched', function (): void {
    $ctx = Harness::context();
    expect((new DefaultContentRewriter())->rewrite('', [], $ctx))->toBe('');
});
