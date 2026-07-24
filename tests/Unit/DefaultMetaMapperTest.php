<?php

declare(strict_types=1);

use IdempotentImport\Config;
use IdempotentImport\Mapper\DefaultMetaMapper;
use IdempotentImport\Tests\Support\Harness;

it('renames and drops keys', function (): void {
    $config = new Config(['meta' => ['post' => [
        'rename' => ['old_seo_title' => '_yoast_wpseo_title'],
        'drop'   => ['_edit_lock'],
    ]]]);
    $ctx    = Harness::context(null, $config);
    $mapper = new DefaultMetaMapper();

    $out = $mapper->mapKeys([
        'old_seo_title' => ['Hello'],
        '_edit_lock'    => ['123'],
        'keep'          => ['x'],
    ], 'post', $ctx);

    expect($out)->toHaveKey('_yoast_wpseo_title')
        ->and($out)->not->toHaveKey('old_seo_title')
        ->and($out)->not->toHaveKey('_edit_lock')
        ->and($out['keep'])->toBe(['x']);
});

it('coerces declared numeric values to int', function (): void {
    $config = new Config(['meta' => ['post' => ['numeric' => ['count']]]]);
    $ctx    = Harness::context(null, $config);
    $mapper = new DefaultMetaMapper();
    expect($mapper->transformValues('count', ['12', '0'], 'post', $ctx))->toBe([12, 0]);
});

it('applies role_map to wp_capabilities for users', function (): void {
    $config = new Config(['users' => ['role_map' => ['contributor' => 'author']]]);
    $ctx    = Harness::context(null, $config);
    $mapper = new DefaultMetaMapper();

    $out = $mapper->transformValues('wp_capabilities', [['contributor' => true]], 'user', $ctx);
    expect($out)->toBe([['author' => true]]);
});

it('merges when a rename collides with an existing key', function (): void {
    $config = new Config(['meta' => ['post' => ['rename' => ['a' => 'b']]]]);
    $ctx    = Harness::context(null, $config);
    $mapper = new DefaultMetaMapper();
    $out    = $mapper->mapKeys(['a' => ['1'], 'b' => ['2']], 'post', $ctx);
    expect($out['b'])->toEqualCanonicalizing(['2', '1']);
});
