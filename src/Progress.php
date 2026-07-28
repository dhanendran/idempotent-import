<?php

namespace IdempotentImport;

/**
 * A WP-CLI progress bar, or a silent stand-in when one would be unwanted or
 * impossible: under --quiet, under --verbose (whose per-entity lines would shred
 * a bar), and in tests where WP-CLI is not loaded. WP-CLI itself substitutes a
 * no-op when stdout is piped, so redirected runs stay free of carriage returns.
 */
class Progress {

	/** @var object|null Underlying WP-CLI bar; null while silent. */
	protected $bar = null;

	/**
	 * @param string $label
	 * @param int    $count
	 * @param bool   $enabled
	 */
	public function __construct( $label, $count, $enabled ) {
		if ( $enabled && function_exists( 'WP_CLI\Utils\make_progress_bar' ) ) {
			$this->bar = \WP_CLI\Utils\make_progress_bar( $label, max( 1, (int) $count ) );
		}
	}

	/**
	 * @return void
	 */
	public function tick() {
		if ( $this->bar ) {
			$this->bar->tick();
		}
	}

	/**
	 * Idempotent, so calling it from a finally block after an early exit is safe.
	 *
	 * @return void
	 */
	public function finish() {
		if ( $this->bar ) {
			$this->bar->finish();
			$this->bar = null;
		}
	}
}
