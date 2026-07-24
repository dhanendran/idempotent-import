<?php

namespace IdempotentImport\Contracts;

/**
 * Persistent old->new id map, namespaced per source site.
 *
 * A record ties (source_key, entity_type, source_id) to a destination id, its
 * status, and a content hash used to detect changes on re-import. This is what
 * makes the importer idempotent and resumable.
 */
interface Ledger {

	/**
	 * Record or update a mapping.
	 *
	 * @param string      $type        Entity type: post|term|term_id|user|comment|url|option.
	 * @param string      $sourceId    Source id / term_taxonomy_id / option_name / url.
	 * @param int|string  $destId      Destination id (or destination value for url/option).
	 * @param string      $status      created|matched|updated|skipped|rewritten
	 * @param string|null $contentHash Hash of the imported payload, or null.
	 * @return void
	 */
	public function remember( $type, $sourceId, $destId, $status, $contentHash = null );

	/**
	 * Look up a mapping.
	 *
	 * @param string $type
	 * @param string $sourceId
	 * @return array{dest_id:string,status:string,content_hash:?string}|null
	 */
	public function lookup( $type, $sourceId );

	/**
	 * All mappings for a type as [sourceId => destId].
	 *
	 * @param string $type
	 * @return array<string,string>
	 */
	public function all( $type );
}
