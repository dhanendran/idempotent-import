<?php

namespace IdempotentImport\Contracts;

use IdempotentImport\Context;

/**
 * Rewrites references embedded inside post_content: Gutenberg block attributes
 * that carry ids (e.g. wp:image {"id":123}), the wp-image-{id} body class,
 * shortcode id attributes, and internal permalinks / attachment URLs.
 *
 * Runs in phase 2 once the IdMap and URL map are complete.
 */
interface ContentRewriter {

	/**
	 * @param string  $html   Raw post_content.
	 * @param array   $post   Decoded source post (for context).
	 * @param Context $ctx
	 * @return string Rewritten content.
	 */
	public function rewrite( $html, array $post, Context $ctx );
}
