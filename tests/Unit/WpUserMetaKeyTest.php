<?php

declare(strict_types=1);

use IdempotentImport\Wp;

/**
 * Exposes the protected key mapper. Wp itself needs no WordPress to construct;
 * userMetaKey only reaches for $wpdb->get_blog_prefix().
 */
final class WpKeyProbe extends Wp
{
    public function key(string $key): string
    {
        return $this->userMetaKey($key);
    }
}

beforeEach(function (): void {
    $GLOBALS['wpdb'] = new class {
        public function get_blog_prefix(): string
        {
            return 'wp_7_';
        }
    };
});

it('rebases the canonical role keys onto the destination blog prefix', function (): void {
    $wp = new WpKeyProbe();

    expect($wp->key('wp_capabilities'))->toBe('wp_7_capabilities');
    expect($wp->key('wp_user_level'))->toBe('wp_7_user_level');
});

it('rebases the wp_N_ multisite form a raw snapshot may still carry', function (): void {
    $wp = new WpKeyProbe();

    expect($wp->key('wp_5_capabilities'))->toBe('wp_7_capabilities');
    expect($wp->key('wp_12_user_level'))->toBe('wp_7_user_level');
});

it('leaves plugin meta that merely ends in a role key alone', function (string $key): void {
    expect((new WpKeyProbe())->key($key))->toBe($key);
})->with([
    'wpseo_capabilities',
    'woocommerce_capabilities',
    'my_site_capabilities',
    'wp_capabilities_extra',
    'closedpostboxes_page',
]);
