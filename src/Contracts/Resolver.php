<?php

namespace IdempotentImport\Contracts;

use IdempotentImport\Context;

/**
 * Decides whether an incoming source entity maps to an entity that already
 * exists on the destination (merge) or should be created fresh.
 *
 * Returning an existing destination id makes the import merge into that entity;
 * returning null creates a new one. One resolver per entity type; projects can
 * replace the defaults via the Registry or the
 * `idempotent_import_resolve_{type}` filter.
 */
interface Resolver {

	/**
	 * @param array   $entity Decoded source entity.
	 * @param Context $ctx
	 * @return int|null Existing destination id, or null to create.
	 */
	public function resolve( array $entity, Context $ctx );
}
