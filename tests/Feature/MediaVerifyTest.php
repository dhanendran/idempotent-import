<?php

declare(strict_types=1);

use IdempotentImport\Logger;
use IdempotentImport\MediaVerifier;
use IdempotentImport\Snapshot;
use IdempotentImport\Tests\Support\FakeWordPress;
use IdempotentImport\Tests\Support\SnapshotBuilder;

/**
 * A snapshot holding one image with two generated sizes, plus an ordinary post
 * the verifier must ignore.
 */
function mediaSnapshot(?array $attachmentMeta = null): string
{
    $b = new SnapshotBuilder(tmpdir());
    $b->post(8821, [
        'post_type'      => 'attachment',
        'attachment_url' => 'https://source.test/wp-content/uploads/2024/03/photo.jpg',
        'meta'           => $attachmentMeta ?? [
            '_wp_attached_file'       => ['2024/03/photo.jpg'],
            '_wp_attachment_metadata' => [[
                'file'  => '2024/03/photo.jpg',
                'sizes' => [
                    'medium' => ['file' => 'photo-300x225.jpg'],
                    'large'  => ['file' => 'photo-1024x768.jpg'],
                ],
            ]],
        ],
    ]);
    $b->post(12, ['post_title' => 'Not media']);
    $b->manifest();
    return $b->dir();
}

function verify(string $dir, FakeWordPress $wp, ?Logger $logger = null): array
{
    return (new MediaVerifier(new Snapshot($dir), $wp, $logger ?? new Logger(null)))->verify();
}

it('passes when the original and every size are present', function (): void {
    $wp             = new FakeWordPress();
    $wp->mediaFiles = ['2024/03/photo.jpg', '2024/03/photo-300x225.jpg', '2024/03/photo-1024x768.jpg'];
    $logger         = new Logger(null);

    $result = verify(mediaSnapshot(), $wp, $logger);

    expect($result['attachments'])->toBe(1)
        ->and($result['files'])->toBe(1)
        ->and($result['files_missing'])->toBe(0)
        ->and($result['sizes'])->toBe(2)
        ->and($result['sizes_missing'])->toBe(0)
        ->and($logger->skipCount())->toBe(0)
        ->and($logger->warnCount())->toBe(0);
});

it('skips an attachment whose original is not on the destination', function (): void {
    $wp             = new FakeWordPress();
    $wp->mediaFiles = ['2024/03/photo-300x225.jpg', '2024/03/photo-1024x768.jpg'];
    $logger         = new Logger(null);

    $result = verify(mediaSnapshot(), $wp, $logger);

    expect($result['files_missing'])->toBe(1)
        ->and($logger->skipCount())->toBe(1)
        ->and($logger->skips()[0]['reason'])->toContain('2024/03/photo.jpg');
});

it('warns on a missing size while the original still counts as present', function (): void {
    $wp             = new FakeWordPress();
    $wp->mediaFiles = ['2024/03/photo.jpg', '2024/03/photo-300x225.jpg'];
    $logger         = new Logger(null);

    $result = verify(mediaSnapshot(), $wp, $logger);

    expect($result['files_missing'])->toBe(0)
        ->and($result['sizes_missing'])->toBe(1)
        ->and($logger->skipCount())->toBe(0)
        ->and($logger->warnCount())->toBe(1);
});

it('checks the untouched original kept beside a scaled upload', function (): void {
    $dir = mediaSnapshot([
        '_wp_attached_file'       => ['2024/03/big-scaled.jpg'],
        '_wp_attachment_metadata' => [[
            'sizes'          => [],
            'original_image' => 'big.jpg',
        ]],
    ]);
    $wp             = new FakeWordPress();
    $wp->mediaFiles = ['2024/03/big-scaled.jpg'];

    $result = verify($dir, $wp);

    expect($result['sizes'])->toBe(1)
        ->and($result['sizes_missing'])->toBe(1);
});

it('reports an attachment with no recorded path as unlocatable', function (): void {
    $logger = new Logger(null);

    $result = verify(mediaSnapshot(['_wp_attachment_image_alt' => ['Alt']]), new FakeWordPress(), $logger);

    expect($result['unlocatable'])->toBe(1)
        ->and($result['files'])->toBe(0)
        ->and($logger->skipCount())->toBe(0)
        ->and($logger->warnCount())->toBe(1);
});

it('resolves size filenames against the original directory, not the uploads root', function (): void {
    $wp             = new FakeWordPress();
    $wp->mediaFiles = ['2024/03/photo.jpg', 'photo-300x225.jpg', 'photo-1024x768.jpg'];

    // Sizes live beside the original; the same basenames at the uploads root are a
    // different file and must not count.
    expect(verify(mediaSnapshot(), $wp)['sizes_missing'])->toBe(2);
});
