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
	 * Loads every row for the type into memory — only safe for small sets. To
	 * reconcile against the destination at scale, use sqlIdentity() and let the
	 * database do the join.
	 *
	 * @param string $type
	 * @return array<string,string>
	 */
	public function all( $type );

	/**
	 * How to reach this ledger from SQL, so callers can join against it server-side
	 * instead of pulling every mapping into PHP.
	 *
	 * @return array{table:string,source_key:string}|null Null when not SQL-backed.
	 */
	public function sqlIdentity();
}
