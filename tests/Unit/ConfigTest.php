<?php

declare(strict_types=1);

use IdempotentImport\Config;

it('exposes safe defaults', function (): void {
    $c = new Config();
    expect($c->get('attachments.strategy'))->toBe('sideload')
        ->and($c->get('options.mode'))->toBe('allowlist')
        ->and($c->get('users.default_author'))->toBe(1)
        ->and($c->get('options.deny'))->toContain('cron');
});

it('deep-merges user config over defaults', function (): void {
    $c = new Config(['users' => ['default_author' => 7]]);
    expect($c->get('users.default_author'))->toBe(7)
        ->and($c->get('users.on_missing'))->toBe('create'); // default retained
});

it('returns normalized meta rules', function (): void {
    $c = new Config(['meta' => ['post' => [
        'rename'  => ['old' => 'new'],
        'drop'    => ['_edit_lock'],
        'numeric' => ['_thumbnail_id'],
        'refs'    => ['hero' => 'attachment'],
    ]]]);
    $rules = $c->metaRules('post');
    expect($rules['rename'])->toBe(['old' => 'new'])
        ->and($rules['drop'])->toBe(['_edit_lock'])
        ->and($rules['refs'])->toBe(['hero' => 'attachment']);

    // Unknown type -> empty rule set.
    expect($c->metaRules('term')['rename'])->toBe([]);
});

it('applies CLI overrides', function (): void {
    $c = new Config();
    $c->applyCliOverrides(['attachments' => 'reference', 'default-author' => '9', 'options' => 'all']);
    expect($c->get('attachments.strategy'))->toBe('reference')
        ->and($c->get('users.default_author'))->toBe(9)
        ->and($c->get('options.mode'))->toBe('all');
});

it('loads a php config file', function (): void {
    $dir = tmpdir();
    file_put_contents("{$dir}/map.php", "<?php return ['users' => ['default_author' => 3]];");
    $c = Config::fromFile("{$dir}/map.php");
    expect($c->get('users.default_author'))->toBe(3);
});
