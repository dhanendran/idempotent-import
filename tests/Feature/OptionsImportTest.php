<?php

declare(strict_types=1);

use IdempotentImport\Config;
use IdempotentImport\Tests\Support\FakeWordPress;
use IdempotentImport\Tests\Support\Harness;
use IdempotentImport\Tests\Support\SnapshotBuilder;

function optionsSnapshot(): string
{
    $b = new SnapshotBuilder(tmpdir());
    $b->post(12345, ['post_name' => 'home']);
    $b->options([
        'blogname'      => ['autoload' => 'yes', 'value' => 'My Site'],
        'cron'          => ['autoload' => 'yes', 'value' => ['x']],
        'page_on_front' => ['autoload' => 'yes', 'value' => '12345'],
    ]);
    $b->manifest();
    return $b->dir();
}

it('applies only allowlisted options by default', function (): void {
    $wp  = new FakeWordPress();
    Harness::run(optionsSnapshot(), $wp);

    expect($wp->options)->toHaveKey('blogname')
        ->and($wp->options)->not->toHaveKey('cron')
        ->and($wp->options)->not->toHaveKey('page_on_front'); // not in default allowlist
    expect($wp->options['blogname']['value'])->toBe('My Site');
});

it('stores option values verbatim, because update_option never un-slashes', function (): void {
    $json = '{"variables":{"variable":[{"variable_key":"next-cohort","variable_value":"January 2027"}]}}';
    $b    = new SnapshotBuilder(tmpdir());
    $b->options(['global_variables' => ['autoload' => 'yes', 'value' => ['site_global_variables' => $json]]]);
    $b->manifest();

    $config = new Config(['options' => ['allow' => ['global_variables']]]);
    $wp     = new FakeWordPress();
    Harness::run($b->dir(), $wp, $config);

    expect($wp->options['global_variables']['value']['site_global_variables'])->toBe($json);
});

it('remaps reference options and still denies cron in all mode', function (): void {
    $config = new Config(['options' => ['mode' => 'all']]);
    $wp     = new FakeWordPress();
    $ctx    = Harness::run(optionsSnapshot(), $wp, $config);

    $destPost = $ctx->idMap->post(12345);
    expect($wp->options)->toHaveKey('page_on_front')
        ->and($wp->options['page_on_front']['value'])->toBe((string) $destPost)
        ->and($wp->options)->not->toHaveKey('cron'); // deny list wins
});
