<?php

declare(strict_types=1);

use IdempotentImport\ArrayLedger;
use IdempotentImport\Config;
use IdempotentImport\Tests\Support\FakeWordPress;
use IdempotentImport\Tests\Support\Harness;
use IdempotentImport\Tests\Support\SnapshotBuilder;

function usersSnapshot(): string
{
    $b = new SnapshotBuilder(tmpdir());
    $b->user(42, [
        'user_login'   => 'alice',
        'user_email'   => 'alice@example.com',
        'display_name' => 'Alice',
        'meta'         => [
            'nickname'        => ['alice'],
            'wp_capabilities' => [['administrator' => true]],
        ],
    ]);
    $b->manifest();
    return $b->dir();
}

it('creates a user and writes meta', function (): void {
    $wp  = new FakeWordPress();
    $ctx = Harness::run(usersSnapshot(), $wp);

    expect($wp->users)->toHaveCount(1);
    $destId = $ctx->idMap->user(42);
    expect($destId)->not->toBeNull()
        ->and($wp->userMeta[$destId]['nickname'][0])->toBe('alice')
        ->and($wp->userMeta[$destId]['wp_capabilities'][0])->toBe(['administrator' => true]);
});

it('is idempotent across two runs', function (): void {
    $wp     = new FakeWordPress();
    $ledger = new ArrayLedger();
    $dir    = usersSnapshot();

    Harness::run($dir, $wp, null, 'update', $ledger);
    Harness::run($dir, $wp, null, 'update', $ledger);

    expect($wp->users)->toHaveCount(1);
});

it('honours a user remap without creating a user', function (): void {
    $config = new Config(['users' => ['remap' => [42 => 7]]]);
    $wp     = new FakeWordPress();
    $ctx    = Harness::run(usersSnapshot(), $wp, $config);

    expect($wp->users)->toHaveCount(0)
        ->and($ctx->idMap->user(42))->toBe(7);
});

it('reuses an existing destination user by login', function (): void {
    $wp = new FakeWordPress();
    $wp->users[500] = ['user_login' => 'alice', 'user_email' => 'alice@example.com'];

    $ctx = Harness::run(usersSnapshot(), $wp);
    expect($ctx->idMap->user(42))->toBe(500)
        ->and($wp->users)->toHaveCount(1); // no new user
});
