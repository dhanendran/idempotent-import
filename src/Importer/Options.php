<?php

namespace IdempotentImport\Importer;

/**
 * Imports options from the single options.json.
 *
 * Options are applied entirely in the rewrite phase, once the IdMap is complete,
 * so reference-bearing options (page_on_front, sticky_posts, default_category,
 * ...) can be remapped to destination ids.
 *
 * Policy is deliberately conservative: the default mode is "allowlist", so only
 * an explicit set of safe options is written. `cron`, `active_plugins`, `siteurl`
 * and `home` are denied by default even in "all" mode unless removed from deny.
 */
class Options extends AbstractImporter {

	/** Options whose value is a single post id. */
	const POST_REF = array( 'page_on_front', 'page_for_posts', 'wp_page_for_privacy_policy' );

	/** Options whose value is a list of post ids. */
	const POST_LIST_REF = array( 'sticky_posts' );

	/** Options whose value is a single term id. */
	const TERM_REF = array( 'default_category', 'default_email_category', 'default_link_category' );

	public function type() {
		return 'option';
	}

	public function createPhase() {
		// Options are applied in the rewrite phase.
	}

	public function rewritePhase() {
		$mode = (string) $this->ctx->config->get( 'options.mode', 'allowlist' );
		if ( 'none' === $mode ) {
			return;
		}
		$allow = array_flip( array_map( 'strval', (array) $this->ctx->config->get( 'options.allow', array() ) ) );
		$deny  = array_flip( array_map( 'strval', (array) $this->ctx->config->get( 'options.deny', array() ) ) );

		foreach ( $this->snapshot->options() as $name => $spec ) {
			$name = (string) $name;
			if ( isset( $deny[ $name ] ) ) {
				$this->ctx->report->record( 'option', 'skipped' );
				continue;
			}
			if ( 'allowlist' === $mode && ! isset( $allow[ $name ] ) ) {
				$this->ctx->report->record( 'option', 'skipped' );
				continue;
			}

			$value    = is_array( $spec ) && array_key_exists( 'value', $spec ) ? $spec['value'] : $spec;
			$autoload = is_array( $spec ) && isset( $spec['autoload'] ) ? $spec['autoload'] : 'yes';
			$value    = $this->rewriteOption( $name, $value );

			if ( $this->ctx->dryRun ) {
				$this->ctx->report->record( 'option', 'updated' );
				continue;
			}

			$this->ctx->wp->updateOption( $name, $this->ctx->decoder->forStorageValue( $value ), $autoload );
			$this->ctx->report->record( 'option', 'updated' );
		}
	}

	/**
	 * @param string $name
	 * @param mixed  $value
	 * @return mixed
	 */
	private function rewriteOption( $name, $value ) {
		if ( in_array( $name, self::POST_REF, true ) && is_scalar( $value ) ) {
			$mapped = $this->ctx->idMap->post( (int) $value );
			return $mapped ? (string) $mapped : $value;
		}
		if ( in_array( $name, self::POST_LIST_REF, true ) && is_array( $value ) ) {
			return array_map(
				function ( $v ) {
					$mapped = $this->ctx->idMap->post( (int) $v );
					return $mapped ? $mapped : $v;
				},
				$value
			);
		}
		if ( in_array( $name, self::TERM_REF, true ) && is_scalar( $value ) ) {
			$mapped = $this->ctx->idMap->termId( (int) $value );
			return $mapped ? (string) $mapped : $value;
		}

		if ( function_exists( 'apply_filters' ) ) {
			$value = apply_filters( 'idempotent_import_rewrite_option', $value, $name, $this->ctx->idMap );
		}
		return $value;
	}
}
