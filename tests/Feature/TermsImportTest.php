<?php

declare(strict_types=1);

use IdempotentImport\Tests\Support\FakeWordPress;
use IdempotentImport\Tests\Support\Harness;
use IdempotentImport\Tests\Support\SnapshotBuilder;

function termsSnapshot(): string
{
    $b = new SnapshotBuilder(tmpdir());
    $b->term(10, ['term_id' => 4, 'taxonomy' => 'category', 'name' => 'Engineering', 'slug' => 'engineering', 'parent' => 0]);
    $b->term(17, ['term_id' => 8, 'taxonomy' => 'category', 'name' => 'Backend', 'slug' => 'backend', 'parent' => 4, 'meta' => ['order' => ['3']]]);
    $b->manifest();
    return $b->dir();
}

it('creates terms and records all three mappings', function (): void {
    $wp  = new FakeWordPress();
    $ctx = Harness::run(termsSnapshot(), $wp);

    expect($wp->terms)->toHaveCount(2)
        ->and($ctx->idMap->term(17))->not->toBeNull()
        ->and($ctx->idMap->termId(8))->not->toBeNull()
        ->and($ctx->idMap->ttIdToTermId(17))->toBe($ctx->idMap->termId(8));
});

it('sets the child parent to the destination parent term_id', function (): void {
    $wp  = new FakeWordPress();
    $ctx = Harness::run(termsSnapshot(), $wp);

    $childTermId  = $ctx->idMap->ttIdToTermId(17);
    $parentTermId = $ctx->idMap->termId(4);
    expect($wp->terms[$childTermId]['parent'])->toBe($parentTermId);
});

it('writes term meta', function (): void {
    $wp  = new FakeWordPress();
    $ctx = Harness::run(termsSnapshot(), $wp);
    $childTermId = $ctx->idMap->ttIdToTermId(17);
    expect($wp->termMeta[$childTermId]['order'][0])->toBe('3');
});
