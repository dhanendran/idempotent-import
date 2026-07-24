<?php

declare(strict_types=1);

use IdempotentImport\ArrayLedger;
use IdempotentImport\IdMap;

it('records and resolves each entity type', function (): void {
    $map = new IdMap(new ArrayLedger());
    $map->rememberPost(12345, 1001);
    $map->rememberUser(42, 100);
    $map->rememberComment(991, 3001);
    $map->rememberTerm(17, 517);
    $map->rememberTermId(8, 217);
    $map->rememberTtIdToTermId(17, 217);
    $map->rememberUrl('https://src/a.jpg', 'https://dst/a.jpg');

    expect($map->post(12345))->toBe(1001)
        ->and($map->user(42))->toBe(100)
        ->and($map->comment(991))->toBe(3001)
        ->and($map->term(17))->toBe(517)
        ->and($map->termId(8))->toBe(217)
        ->and($map->ttIdToTermId(17))->toBe(217)
        ->and($map->url('https://src/a.jpg'))->toBe('https://dst/a.jpg');
});

it('returns null for unknown ids', function (): void {
    $map = new IdMap(new ArrayLedger());
    expect($map->post(1))->toBeNull()
        ->and($map->user(1))->toBeNull()
        ->and($map->url('missing'))->toBeNull();
});

it('exposes records with content hash for conflict detection', function (): void {
    $ledger = new ArrayLedger();
    $map    = new IdMap($ledger);
    $map->rememberPost(5, 500, 'created', 'abc');
    $rec = $map->record('post', 5);
    expect($rec['dest_id'])->toBe('500')
        ->and($rec['content_hash'])->toBe('abc');
});

it('returns all url mappings', function (): void {
    $map = new IdMap(new ArrayLedger());
    $map->rememberUrl('a', '1');
    $map->rememberUrl('b', '2');
    expect($map->allUrls())->toBe(['a' => '1', 'b' => '2']);
});
