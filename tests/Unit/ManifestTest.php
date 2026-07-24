<?php

declare(strict_types=1);

use IdempotentImport\Manifest;

it('reports schema compatibility by major version', function (): void {
    expect((new Manifest(['schema_version' => '1.4.2']))->isCompatible())->toBeTrue();
    expect((new Manifest(['schema_version' => '2.0.0']))->isCompatible())->toBeFalse();
    expect((new Manifest([]))->isCompatible())->toBeFalse();
});

it('derives a stable source key from url + blog id', function (): void {
    $a = new Manifest(['source' => ['site_url' => 'https://a.test', 'blog_id' => null]]);
    $b = new Manifest(['source' => ['site_url' => 'https://a.test', 'blog_id' => null]]);
    $c = new Manifest(['source' => ['site_url' => 'https://a.test', 'blog_id' => 5]]);
    expect($a->sourceKey())->toBe($b->sourceKey())
        ->and($a->sourceKey())->not->toBe($c->sourceKey());
});

it('reads counts and source fields', function (): void {
    $m = new Manifest(['counts' => ['posts' => 12], 'source' => ['site_url' => 'https://x.test', 'blog_id' => 3]]);
    expect($m->count('posts'))->toBe(12)
        ->and($m->count('terms'))->toBe(0)
        ->and($m->sourceUrl())->toBe('https://x.test')
        ->and($m->sourceBlogId())->toBe(3);
});
