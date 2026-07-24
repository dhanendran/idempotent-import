<?php

namespace IdempotentImport\Contracts;

/**
 * A whole entity type in the two-phase pipeline. Mirrors the exporter's "drop a
 * class under Exporter/" extension point: implement both phases and register the
 * importer in the run order to add a new entity type.
 */
interface EntityImporter {

	/**
	 * The logical type key, e.g. "post", "term". Used for reporting.
	 *
	 * @return string
	 */
	public function type();

	/**
	 * Phase 1: create/match every entity and populate the IdMap. Must not rely
	 * on references to entities created by other importers.
	 *
	 * @return void
	 */
	public function createPhase();

	/**
	 * Phase 2: rewrite references now that the IdMap is complete.
	 *
	 * @return void
	 */
	public function rewritePhase();
}
