<?php

declare(strict_types=1);

/**
 * Test bootstrap. Loads the production autoloader and registers the test
 * autoloader. The importer talks to WordPress only through the WordPress
 * gateway interface, so tests inject an in-memory FakeWordPress instead of
 * shimming WordPress functions.
 */

require __DIR__ . '/../src/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'IdempotentImport\\Tests\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path     = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) {
        require_once $path;
    }
});
