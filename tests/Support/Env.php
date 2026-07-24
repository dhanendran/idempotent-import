<?php

declare(strict_types=1);

namespace IdempotentImport\Tests\Support;

/**
 * Temp-path bookkeeping for tests.
 */
class Env
{
    /** @var string[] */
    public static array $tempPaths = [];

    public static function tmpdir(string $prefix): string
    {
        $path = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(6));
        @mkdir($path, 0777, true);
        self::$tempPaths[] = $path;
        return $path;
    }

    public static function cleanup(): void
    {
        foreach (self::$tempPaths as $p) {
            self::rrmdir($p);
        }
        self::$tempPaths = [];
    }

    public static function rrmdir(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($path);
    }
}
