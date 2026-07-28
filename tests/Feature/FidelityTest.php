<?php

declare(strict_types=1);

use IdempotentImport\ArrayLedger;
use IdempotentImport\Config;
use IdempotentImport\Contracts\WordPress;
use IdempotentImport\Tests\Support\FakeWordPress;
use IdempotentImport\Tests\Support\Harness;
use IdempotentImport\Tests\Support\SnapshotBuilder;

/**
 * Guards the round-trip properties a migration is judged on: the destination
 * must end up holding what the snapshot says, and a dry run must leave nothing
 * behind.
 */
function fidelityConfig(): Config
{
    return new Config([
        'posts'       => ['preserve_ids' => true],
        'attachments' => ['strategy' => 'reference'],
    ]);
}

it('does not write to the ledger during a dry run', function (): void {
    $b = new SnapshotBuilder(tmpdir());
    $b->user(501, ['user_login' => 'ada', 'user_email' => 'ada@example.test']);
    $b->post(4242, ['post_title' => 'Hello', 'post_name' => 'hello']);
    $b->manifest();

    $wp = new FakeWordPress();
    // The user already exists on the destination: the path that used to record a
    // `matched` mapping before checking dryRun.
    $wp->users[9] = ['ID' => 9, 'user_login' => 'ada', 'user_email' => 'ada@example.test'];

    $ledger = new ArrayLedger();
    Harness::run($b->dir(), $wp, fidelityConfig(), 'update', $ledger, null, true);

    expect($ledger->all('user'))->toBe([]);
    expect($ledger->all('post'))->toBe([]);
    expect($wp->posts)->toBe([]);
});

it('keeps a meta key containing a backslash intact', function (): void {
    $b = new SnapshotBuilder(tmpdir());
    $b->post(4242, [
        'post_title' => 'Hello',
        'post_name'  => 'hello',
        'meta'       => ['ec_key_with\\backslash' => ['kept']],
    ]);
    $b->manifest();

    $wp = new FakeWordPress();
    Harness::run($b->dir(), $wp, fidelityConfig());

    // The fake stores what WordPress would after add_metadata() unslashes the key,
    // so a key that survives slashing arrives with its backslash.
    expect($wp->postMeta[4242])->toHaveKey('ec_key_with\\backslash');
    expect($wp->postMeta[4242]['ec_key_with\\backslash'])->toBe(['kept']);
});

it('prunes destination meta keys the snapshot does not have', function (): void {
    $b = new SnapshotBuilder(tmpdir());
    $b->post(4242, [
        'post_title' => 'Hello',
        'post_name'  => 'hello',
        'meta'       => ['keep_me' => ['yes']],
    ]);
    $b->manifest();

    $wp = new FakeWordPress();
    // Stand in for the keys WordPress seeds on insert (_pingme, _encloseme) and for
    // a key that was deleted at the source since the last run.
    $wp->onInsertPostMeta = ['_pingme' => ['1'], 'stale_key' => ['old']];

    Harness::run($b->dir(), $wp, fidelityConfig());

    expect(array_keys($wp->postMeta[4242]))->toBe(['keep_me']);
});

it('leaves an attachment\'s binary meta alone when it withheld the source values', function (): void {
    $b = new SnapshotBuilder(tmpdir());
    $b->post(8821, [
        'post_type'      => 'attachment',
        'post_title'     => 'Photo',
        'post_name'      => 'photo',
        'attachment_url' => 'https://source.test/wp-content/uploads/2024/03/photo.jpg',
        'meta'           => [
            '_wp_attached_file'        => ['2024/03/photo.jpg'],
            '_wp_attachment_image_alt' => ['A lecture hall'],
        ],
    ]);
    $b->manifest();

    $wp  = new FakeWordPress();
    $ctx = Harness::run($b->dir(), $wp, new Config([
        'attachments' => ['strategy' => 'sideload'],
    ]));

    $destId = $ctx->idMap->post(8821);
    expect($destId)->not->toBeNull();

    // Sideload derived its own file, so the destination's _wp_attached_file must
    // survive the prune rather than being deleted as "not in the snapshot".
    expect($wp->postMeta[$destId])->toHaveKey('_wp_attached_file');
    expect($wp->postMeta[$destId]['_wp_attachment_image_alt'])->toBe(['A lecture hall']);
});

it('re-syncs an attachment whose source changed', function (): void {
    $make = function (string $alt): string {
        $b = new SnapshotBuilder(tmpdir());
        $b->post(8821, [
            'post_type'      => 'attachment',
            'post_title'     => 'Photo',
            'post_name'      => 'photo',
            'attachment_url' => 'https://source.test/wp-content/uploads/2024/03/photo.jpg',
            'meta'           => [
                '_wp_attached_file'        => ['2024/03/photo.jpg'],
                '_wp_attachment_image_alt' => [$alt],
            ],
        ]);
        $b->manifest();
        return $b->dir();
    };

    $wp     = new FakeWordPress();
    $ledger = new ArrayLedger();

    Harness::run($make('A lecture hall'), $wp, fidelityConfig(), 'update', $ledger);
    expect($wp->postMeta[8821]['_wp_attachment_image_alt'])->toBe(['A lecture hall']);

    $ctx = Harness::run($make('A packed lecture hall'), $wp, fidelityConfig(), 'update', $ledger);

    expect($wp->postMeta[8821]['_wp_attachment_image_alt'])->toBe(['A packed lecture hall']);
    expect($ctx->report->outcomes()['attachment']['updated'])->toBe(1);
});

it('restates the columns WordPress would re-derive whenever it updates a post', function (): void {
    $make = function (string $title): string {
        $b = new SnapshotBuilder(tmpdir());
        $b->post(4242, [
            'post_title'        => $title,
            'post_name'         => 'scheduled',
            'post_status'       => 'future',
            'post_date'         => '2019-01-01 09:00:00',
            'post_date_gmt'     => '2019-01-01 09:00:00',
            'post_modified'     => '2020-06-15 10:00:00',
            'post_modified_gmt' => '2020-06-15 10:00:00',
        ]);
        $b->manifest();
        return $b->dir();
    };

    $wp     = new FakeWordPress();
    $ledger = new ArrayLedger();

    Harness::run($make('Scheduled'), $wp, fidelityConfig(), 'update', $ledger);
    Harness::run($make('Scheduled, retitled'), $wp, fidelityConfig(), 'update', $ledger);

    $update = null;
    foreach ($wp->updatedPostFields as $call) {
        if (4242 === $call['id']) {
            $update = $call['fields'];
        }
    }

    expect($update)->not->toBeNull();
    foreach (WordPress::PRESERVED_COLUMNS as $column) {
        expect($update)->toHaveKey($column);
    }
    expect($update['post_status'])->toBe('future');
    expect($update['post_name'])->toBe('scheduled');
    expect($update['post_modified'])->toBe('2020-06-15 10:00:00');
    expect($wp->posts[4242]['post_title'])->toBe('Scheduled, retitled');
});
