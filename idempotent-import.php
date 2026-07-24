<?php
/**
 * Plugin Name: WP Idempotent Import
 * Description: WP-CLI command that imports a deterministic JSON snapshot produced by WP Idempotent Export into a WordPress site, idempotently and without wiping the destination.
 * Version:     1.0.0
 * Requires PHP: 7.4
 * License:     MIT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/src/autoload.php';

WP_CLI::add_command( 'idempotent-import', \IdempotentImport\Command::class );
