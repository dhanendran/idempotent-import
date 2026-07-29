<?php

declare(strict_types=1);

use IdempotentImport\Config;
use IdempotentImport\Tests\Support\FakeWordPress;
use IdempotentImport\Tests\Support\Harness;
use IdempotentImport\Tests\Support\SnapshotBuilder;

const SRC_URL = 'https://source.test/wp-content/uploads/2024/03/photo.jpg';

function attachmentSnapshot(string $postContent = ''): string
{
    $b = new SnapshotBuilder(tmpdir());
    $b->post(8821, [
        'post_type'      => 'attachment',
        'post_title'     => 'Photo',
        'attachment_url' => SRC_URL,
        'meta'           => ['_wp_attached_file' => ['2024/03/photo.jpg']],
    ]);
    if ('' !== $postContent) {
        $b->post(12, ['post_content' => $postContent]);
    }
    $b->manifest();
    return $b->dir();
}

function referenceConfig(): Config
{
    return new Config(['attachments' => ['strategy' => 'reference']]);
}

it('sideloads by default and maps the source url to a destination url', function (): void {
    $wp  = new FakeWordPress();
    $ctx = Harness::run(attachmentSnapshot(), $wp);

    $destId = $ctx->idMap->post(8821);
    expect($destId)->not->toBeNull()
        ->and($ctx->idMap->url(SRC_URL))->toContain('dest.test');
});

it('reference strategy rewrites the guid to the destination and does not download', function (): void {
    $wp  = new FakeWordPress();
    $ctx = Harness::run(attachmentSnapshot(), $wp, referenceConfig());

    // Spec 3.3.7 rewrites guid with the other URLs; the relative path is untouched.
    $destId = $ctx->idMap->post(8821);
    expect($wp->posts[$destId]['guid'])->toBe('https://dest.test/wp-content/uploads/2024/03/photo.jpg')
        ->and($wp->postMeta[$destId]['_wp_attached_file'][0])->toBe('2024/03/photo.jpg');
});

it('reference strategy leaves the guid alone when the path is not relative', function (): void {
    $b = new SnapshotBuilder(tmpdir());
    $b->post(8822, [
        'post_type'      => 'attachment',
        'attachment_url' => 'https://cdn.test/remote.jpg',
        'meta'           => ['_wp_attached_file' => ['https://cdn.test/remote.jpg']],
    ]);
    $b->manifest();

    $wp  = new FakeWordPress();
    $ctx = Harness::run($b->dir(), $wp, referenceConfig());

    $destId = $ctx->idMap->post(8822);
    expect($wp->posts[$destId]['guid'])->toBe('https://cdn.test/remote.jpg');
});

it('reference strategy maps the preserved path to the destination url', function (): void {
    $wp  = new FakeWordPress();
    $ctx = Harness::run(attachmentSnapshot(), $wp, referenceConfig());

    // Nothing was downloaded, but wp_get_attachment_url() resolves the preserved
    // path against the destination's uploads base — so that is what content has to
    // point at, not the source host.
    expect($ctx->idMap->url(SRC_URL))->toBe('https://dest.test/wp-content/uploads/2024/03/photo.jpg');
});

it('reference strategy rewrites every image size via the uploads base', function (): void {
    $content = '<img src="https://source.test/wp-content/uploads/2024/03/photo-1024x768.jpg" '
        . 'srcset="https://source.test/wp-content/uploads/2024/03/photo-300x225.jpg 300w" />';

    $wp  = new FakeWordPress();
    $ctx = Harness::run(attachmentSnapshot($content), $wp, referenceConfig());

    $destPost = $ctx->idMap->post(12);
    expect($wp->posts[$destPost]['post_content'])
        ->toContain('https://dest.test/wp-content/uploads/2024/03/photo-1024x768.jpg')
        ->toContain('https://dest.test/wp-content/uploads/2024/03/photo-300x225.jpg')
        ->not->toContain('source.test');
});

it('does not map the uploads base when source and destination paths are identical', function (): void {
    $wp             = new FakeWordPress();
    $wp->uploadsUrl = 'https://source.test/wp-content/uploads';
    $ctx            = Harness::run(attachmentSnapshot(), $wp, referenceConfig());

    // Same domain and path: the only entry is the attachment's own identical pair.
    expect($ctx->idMap->allUrls())->toBe([SRC_URL => SRC_URL]);
});

it('does not map the uploads base for a sideload, which derives its own path', function (): void {
    $wp  = new FakeWordPress();
    $ctx = Harness::run(attachmentSnapshot(), $wp);

    // The sideload landed on uploads/photo.jpg, not uploads/2024/03/photo.jpg, so
    // its base cannot stand in for the sizes — mapping it would send them at files
    // the destination never generated.
    expect($ctx->idMap->allUrls())->toHaveCount(1)
        ->and($ctx->idMap->url('https://source.test/wp-content/uploads'))->toBeNull();
});

it('skip strategy imports nothing', function (): void {
    $config = new Config(['attachments' => ['strategy' => 'skip']]);
    $wp     = new FakeWordPress();
    $ctx    = Harness::run(attachmentSnapshot(), $wp, $config);

    expect($ctx->idMap->post(8821))->toBeNull()
        ->and($wp->posts)->toHaveCount(0);
});
