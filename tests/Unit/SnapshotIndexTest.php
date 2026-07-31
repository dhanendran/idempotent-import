<?php

declare(strict_types=1);

use IdempotentImport\Snapshot;
use IdempotentImport\Tests\Support\SnapshotBuilder;

/**
 * A snapshot whose posts are reachable only through files.json, standing in for an
 * object-store filesystem where directory listing is unavailable.
 */
function indexedSnapshot(array $index): string
{
    $b = new SnapshotBuilder(tmpdir('idem-index'));
    $b->post(10, ['post_title' => 'First']);
    $b->post(20, ['post_title' => 'Second']);
    $b->manifest();
    file_put_contents($b->dir() . '/files.json', json_encode($index));
    return $b->dir();
}

/** Relative paths the builder wrote, so the index mirrors a real tree. */
function postPaths(string $dir): array
{
    $paths = [];
    $iter  = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir . '/posts', FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iter as $f) {
        if ($f->isFile()) {
            $paths[] = ltrim(str_replace($dir, '', $f->getPathname()), '/');
        }
    }
    sort($paths);
    return $paths;
}

it('enumerates posts from files.json instead of listing directories', function (): void {
    $dir = indexedSnapshot([]);
    file_put_contents($dir . '/files.json', json_encode(postPaths($dir)));

    $ids = [];
    foreach ((new Snapshot($dir))->iterate('posts') as $post) {
        $ids[] = $post['ID'];
    }

    expect($ids)->toBe([10, 20]);
});

it('yields index entries in sorted path order', function (): void {
    $dir   = indexedSnapshot([]);
    $paths = postPaths($dir);
    file_put_contents($dir . '/files.json', json_encode(array_reverse($paths)));

    $seen = array_keys(iterator_to_array((new Snapshot($dir))->iterate('posts')));

    expect($seen)->toBe($paths);
});

it('confines enumeration to the requested subdirectory', function (): void {
    $dir = indexedSnapshot([]);
    file_put_contents(
        $dir . '/files.json',
        json_encode(array_merge(postPaths($dir), ['terms/category/1.json']))
    );

    $snapshot = new Snapshot($dir);

    expect(iterator_to_array($snapshot->iterate('posts')))->toHaveCount(2)
        ->and($snapshot->has('posts'))->toBeTrue()
        ->and($snapshot->has('comments'))->toBeFalse();
});

it('ignores non-json and traversing index entries', function (): void {
    $dir = indexedSnapshot([]);
    file_put_contents(
        $dir . '/files.json',
        json_encode(array_merge(postPaths($dir), [
            'posts/report.log',
            '../outside/9.json',
            'posts/../../escape.json',
        ]))
    );

    expect(iterator_to_array((new Snapshot($dir))->iterate('posts')))->toHaveCount(2);
});

it('falls back to walking the tree when there is no index', function (): void {
    $dir = indexedSnapshot([]);
    unlink($dir . '/files.json');

    $ids = [];
    foreach ((new Snapshot($dir))->iterate('posts') as $post) {
        $ids[] = $post['ID'];
    }

    expect($ids)->toBe([10, 20]);
});

it('judges readability on manifest.json, not on is_dir', function (): void {
    $dir = indexedSnapshot([]);
    unlink($dir . '/manifest.json');

    expect(fn () => (new Snapshot($dir))->assertReadable())
        ->toThrow(RuntimeException::class, 'no manifest.json');
});
