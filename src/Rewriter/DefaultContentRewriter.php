<?php

namespace IdempotentImport\Rewriter;

use IdempotentImport\Context;
use IdempotentImport\Contracts\ContentRewriter;

/**
 * Block- and URL-aware post_content rewriter.
 *
 * Rewrites, using the completed IdMap / URL map:
 *   - core block id attributes in block comment delimiters, e.g.
 *     <!-- wp:image {"id":123,...} --> and wp:cover / wp:media-text / etc.
 *   - the wp-image-{id} body class emitted by the image block
 *   - source attachment URLs -> destination attachment URLs
 *
 * Only ids that resolve to a destination post/attachment are touched; unknown
 * ids are left intact. Projects with custom blocks that embed ids should
 * register an additional ContentRewriter.
 */
class DefaultContentRewriter implements ContentRewriter {

	public function rewrite( $html, array $post, Context $ctx ) {
		if ( '' === (string) $html ) {
			return $html;
		}

		$html = $this->rewriteBlockIds( $html, $ctx );
		$html = $this->rewriteImageClasses( $html, $ctx );
		$html = $this->rewriteUrls( $html, $ctx );

		if ( function_exists( 'apply_filters' ) ) {
			$html = apply_filters( 'idempotent_import_rewrite_post_content', $html, $post, $ctx->idMap );
		}
		return $html;
	}

	/**
	 * Rewrite "id":N inside Gutenberg block delimiters.
	 *
	 * @param string  $html
	 * @param Context $ctx
	 * @return string
	 */
	private function rewriteBlockIds( $html, Context $ctx ) {
		return preg_replace_callback(
			'/("id"\s*:\s*)(\d+)/',
			function ( $m ) use ( $ctx ) {
				$dest = $ctx->idMap->post( (int) $m[2] );
				return $dest ? $m[1] . $dest : $m[0];
			},
			$html
		);
	}

	/**
	 * Rewrite wp-image-{id} classes.
	 *
	 * @param string  $html
	 * @param Context $ctx
	 * @return string
	 */
	private function rewriteImageClasses( $html, Context $ctx ) {
		return preg_replace_callback(
			'/wp-image-(\d+)/',
			function ( $m ) use ( $ctx ) {
				$dest = $ctx->idMap->post( (int) $m[1] );
				return $dest ? 'wp-image-' . $dest : $m[0];
			},
			$html
		);
	}

	/**
	 * Replace known source attachment URLs with their destination URLs.
	 *
	 * @param string  $html
	 * @param Context $ctx
	 * @return string
	 */
	private function rewriteUrls( $html, Context $ctx ) {
		$urls = $ctx->idMap->allUrls();
		if ( empty( $urls ) ) {
			return $html;
		}
		// Longest source URLs first, so a base URL never shadows a more specific one.
		uksort(
			$urls,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		$from = array_keys( $urls );
		$to   = array_values( $urls );
		return str_replace( $from, $to, $html );
	}
}
