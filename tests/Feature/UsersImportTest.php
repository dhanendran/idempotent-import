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

it('attaches the per-blog role to a matched user without touching its profile', function (): void {
    $wp = new FakeWordPress();
    // Existing network account with its own profile already set (e.g. imported for another site).
    $wp->users[500]    = ['user_login' => 'alice', 'user_email' => 'alice@example.com'];
    $wp->userMeta[500] = ['nickname' => ['existing-nick']];

    Harness::run(usersSnapshot(), $wp);

    expect($wp->users)->toHaveCount(1)                                        // no duplicate account
        ->and($wp->userMeta[500]['wp_capabilities'][0])->toBe(['administrator' => true]) // role attached
        ->and($wp->userMeta[500]['nickname'][0])->toBe('existing-nick');      // global profile untouched
});

it('keeps the destination role when users.attach_roles_to_matched is off', function (): void {
    $wp = new FakeWordPress();
    // Already an editor on this blog; the snapshot says administrator.
    $wp->users[500]    = ['user_login' => 'alice', 'user_email' => 'alice@example.com'];
    $wp->userMeta[500] = ['wp_capabilities' => [['editor' => true]]];

    $config = new Config(['users' => ['attach_roles_to_matched' => false]]);

    Harness::run(usersSnapshot(), $wp, $config);

    expect($wp->userMeta[500]['wp_capabilities'][0])->toBe(['editor' => true]);
});

it('re-attaches the blog role when a user was removed from the site', function (): void {
    $wp     = new FakeWordPress();
    $ledger = new ArrayLedger();
    $dir    = usersSnapshot();

    Harness::run($dir, $wp, null, 'update', $ledger);
    $destId = $wp->getUserIdBy('login', 'alice');

    // Someone removes the user from the site: the network account survives,
    // only the per-blog capabilities are deleted.
    unset($wp->userMeta[$destId]['wp_capabilities']);

    $ctx = Harness::run($dir, $wp, null, 'update', $ledger);

    expect($wp->users)->toHaveCount(1)                                          // no duplicate account
        ->and($wp->userMeta[$destId]['wp_capabilities'][0])->toBe(['administrator' => true])
        ->and($ctx->report->outcomes()['user']['restored'])->toBe(1)
        ->and($ctx->report->outcomes()['user']['unchanged'])->toBe(0);
});

it('re-creates a user whose destination account was deleted outright', function (): void {
    $wp     = new FakeWordPress();
    $ledger = new ArrayLedger();
    $dir    = usersSnapshot();

    Harness::run($dir, $wp, null, 'update', $ledger);
    $destId = $wp->getUserIdBy('login', 'alice');
    unset($wp->users[$destId], $wp->userMeta[$destId]);

    $ctx = Harness::run($dir, $wp, null, 'update', $ledger);

    expect($wp->users)->toHaveCount(1)
        ->and($ctx->report->outcomes()['user']['restored'])->toBe(1)
        ->and($ctx->idMap->user(42))->not->toBe($destId); // ledger repointed at the new account
});

it('leaves an intact user alone', function (): void {
    $wp     = new FakeWordPress();
    $ledger = new ArrayLedger();
    $dir    = usersSnapshot();

    Harness::run($dir, $wp, null, 'update', $ledger);
    $ctx = Harness::run($dir, $wp, null, 'update', $ledger);

    expect($ctx->report->outcomes()['user']['unchanged'])->toBe(1)
        ->and($ctx->report->outcomes()['user']['restored'])->toBe(0);
});
