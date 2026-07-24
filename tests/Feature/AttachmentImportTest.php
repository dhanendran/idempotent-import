<?php

declare(strict_types=1);

use IdempotentImport\Config;
use IdempotentImport\Tests\Support\FakeWordPress;
use IdempotentImport\Tests\Support\Harness;
use IdempotentImport\Tests\Support\SnapshotBuilder;

function attachmentSnapshot(): string
{
    $b = new SnapshotBuilder(tmpdir());
    $b->post(8821, [
        'post_type'      => 'attachment',
        'post_title'     => 'Photo',
        'attachment_url' => 'https://source.test/wp-content/uploads/photo.jpg',
        'meta'           => ['_wp_attached_file' => ['2024/03/photo.jpg']],
    ]);
    $b->manifest();
    return $b->dir();
}

it('sideloads by default and maps the source url to a destination url', function (): void {
    $wp  = new FakeWordPress();
    $ctx = Harness::run(attachmentSnapshot(), $wp);

    $destId = $ctx->idMap->post(8821);
    expect($destId)->not->toBeNull()
        ->and($ctx->idMap->url('https://source.test/wp-content/uploads/photo.jpg'))->toContain('dest.test');
});

it('reference strategy keeps the original url and does not download', function (): void {
    $config = new Config(['attachments' => ['strategy' => 'reference']]);
    $wp     = new FakeWordPress();
    $ctx    = Harness::run(attachmentSnapshot(), $wp, $config);

    $destId = $ctx->idMap->post(8821);
    expect($wp->posts[$destId]['guid'])->toBe('https://source.test/wp-content/uploads/photo.jpg')
        ->and($ctx->idMap->url('https://source.test/wp-content/uploads/photo.jpg'))
        ->toBe('https://source.test/wp-content/uploads/photo.jpg');
});

it('skip strategy imports nothing', function (): void {
    $config = new Config(['attachments' => ['strategy' => 'skip']]);
    $wp     = new FakeWordPress();
    $ctx    = Harness::run(attachmentSnapshot(), $wp, $config);

    expect($ctx->idMap->post(8821))->toBeNull()
        ->and($wp->posts)->toHaveCount(0);
});
