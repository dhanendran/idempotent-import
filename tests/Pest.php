<?php

declare(strict_types=1);

use IdempotentImport\Tests\Support\Env;

uses()
    ->afterEach(function (): void {
        Env::cleanup();
    })
    ->in('Unit', 'Feature');

/**
 * Temp dir unique to the current test, registered for cleanup.
 */
function tmpdir(string $prefix = 'idem-import'): string
{
    return Env::tmpdir($prefix);
}
