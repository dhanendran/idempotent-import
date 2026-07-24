<?php

declare(strict_types=1);

use IdempotentImport\Decoder;

it('slashes strings for storage (fallback addslashes without WP)', function (): void {
    $d = new Decoder();
    // Without wp_slash loaded, the fallback is addslashes.
    expect($d->forStorageValue("O'Brien"))->toBe("O\\'Brien");
});

it('recurses into arrays and leaves scalars untouched', function (): void {
    $d   = new Decoder();
    $out = $d->forStorageRow(['a' => "quote'", 'n' => 5, 'nested' => ['x' => "y'z"]]);
    expect($out['a'])->toBe("quote\\'")
        ->and($out['n'])->toBe(5)
        ->and($out['nested']['x'])->toBe("y\\'z");
});

it('leaves non-strings alone', function (): void {
    $d = new Decoder();
    expect($d->forStorageValue(42))->toBe(42)
        ->and($d->forStorageValue(true))->toBeTrue()
        ->and($d->forStorageValue(null))->toBeNull();
});
