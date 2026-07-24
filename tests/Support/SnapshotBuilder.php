<?php

declare(strict_types=1);

namespace IdempotentImport\Tests\Support;

/**
 * Writes an on-disk snapshot tree in the exporter's layout, for feature tests.
 */
class SnapshotBuilder
{
    private string $dir;
    private array $counts = [];

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');
        @mkdir($this->dir, 0777, true);
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function manifest(array $source = []): self
    {
        $data = [
            'schema_version' => '1.0.0',
            'exported_at'    => '2026-01-01T00:00:00Z',
            'source'         => array_merge([
                'site_url'     => 'https://source.test',
                'blog_id'      => null,
                'is_multisite' => false,
                'wp_version'   => '6.9',
            ], $source),
            'counts'         => $this->counts,
            'filters_applied'=> [],
            'skipped'        => [],
        ];
        $this->write('manifest.json', $data);
        return $this;
    }

    public function user(int $id, array $data): self
    {
        $this->write("users/{$id}.json", array_merge(['ID' => $id, 'meta' => []], $data));
        return $this;
    }

    public function term(int $ttId, array $data): self
    {
        $tax = $data['taxonomy'] ?? 'category';
        $this->write("terms/{$tax}/{$ttId}.json", array_merge([
            'term_taxonomy_id' => $ttId,
            'meta'             => [],
        ], $data));
        return $this;
    }

    public function post(int $id, array $data): self
    {
        $date = $data['post_date_gmt'] ?? '2024-03-15 10:30:00';
        [$y, $m] = [substr($date, 0, 4), substr($date, 5, 2)];
        $this->write("posts/{$y}/{$m}/{$id}.json", array_merge([
            'ID'        => $id,
            'post_type' => 'post',
            'meta'      => [],
            'terms'     => [],
            'comments'  => [],
        ], $data));
        return $this;
    }

    public function comment(int $id, array $data): self
    {
        $date = $data['comment_date_gmt'] ?? '2024-04-01 12:00:00';
        [$y, $m] = [substr($date, 0, 4), substr($date, 5, 2)];
        $this->write("comments/{$y}/{$m}/{$id}.json", array_merge([
            'comment_ID' => $id,
            'meta'       => [],
        ], $data));
        return $this;
    }

    public function options(array $options): self
    {
        $this->write('options.json', $options);
        return $this;
    }

    private function write(string $relative, array $data): void
    {
        $abs = $this->dir . '/' . $relative;
        @mkdir(dirname($abs), 0777, true);
        file_put_contents($abs, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
