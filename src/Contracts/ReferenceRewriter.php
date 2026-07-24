<?php

namespace IdempotentImport\Contracts;

use IdempotentImport\Context;

/**
 * A phase-2 transform that rewrites source ids to destination ids in a single
 * value. Multiple rewriters can be registered; each declares whether it handles
 * a given field via handles().
 *
 * The generic engine calls handles() then rewrite() for meta values, option
 * values, and any other id-bearing field.
 */
interface ReferenceRewriter {

	/**
	 * @param string  $context Where the value came from, e.g. "post.meta.<key>",
	 *                         "post.field.post_parent", "option.<name>".
	 * @param Context $ctx
	 * @return bool
	 */
	public function handles( $context, Context $ctx );

	/**
	 * @param mixed   $value
	 * @param string  $context
	 * @param Context $ctx
	 * @return mixed Rewritten value.
	 */
	public function rewrite( $value, $context, Context $ctx );
}
