<?php

namespace IdempotentImport;

use IdempotentImport\Contracts\Ledger;

/**
 * Semantic facade over the Ledger. Phase 1 populates it as entities are
 * created; phase 2 reads it to rewrite every source id reference to its new
 * destination id.
 *
 * Entity-type keys used in the ledger:
 *   post     source post ID          -> dest post ID          (attachments included)
 *   term     source term_taxonomy_id -> dest term_taxonomy_id
 *   term_id  source term_id          -> dest term_id           (for `parent`)
 *   user     source user ID          -> dest user ID
 *   comment  source comment ID       -> dest comment ID
 *   url      source attachment URL   -> dest attachment URL
 */
class IdMap {

	/** @var Ledger */
	private $ledger;

	/**
	 * @param Ledger $ledger
	 */
	public function __construct( Ledger $ledger ) {
		$this->ledger = $ledger;
	}

	/**
	 * @return Ledger
	 */
	public function ledger() {
		return $this->ledger;
	}

	/* ---- Posts / attachments -------------------------------------------- */

	public function rememberPost( $sourceId, $destId, $status = 'created', $hash = null ) {
		$this->ledger->remember( 'post', $sourceId, $destId, $status, $hash );
	}

	/**
	 * @param int|string $sourceId
	 * @return int|null
	 */
	public function post( $sourceId ) {
		return $this->intOrNull( $this->ledger->lookup( 'post', $sourceId ) );
	}

	/** Attachments share the post id space. */
	public function attachment( $sourceId ) {
		return $this->post( $sourceId );
	}

	/* ---- Terms ----------------------------------------------------------- */

	public function rememberTerm( $sourceTtId, $destTtId, $status = 'created', $hash = null ) {
		$this->ledger->remember( 'term', $sourceTtId, $destTtId, $status, $hash );
	}

	public function term( $sourceTtId ) {
		return $this->intOrNull( $this->ledger->lookup( 'term', $sourceTtId ) );
	}

	public function rememberTermId( $sourceTermId, $destTermId ) {
		$this->ledger->remember( 'term_id', $sourceTermId, $destTermId, 'created' );
	}

	public function termId( $sourceTermId ) {
		return $this->intOrNull( $this->ledger->lookup( 'term_id', $sourceTermId ) );
	}

	/**
	 * Map a source term_taxonomy_id straight to the destination term_id. Posts
	 * reference terms by term_taxonomy_id, but wp_set_post_terms wants term_ids,
	 * so we record this shortcut when the term is created.
	 */
	public function rememberTtIdToTermId( $sourceTtId, $destTermId ) {
		$this->ledger->remember( 'ttid_termid', $sourceTtId, $destTermId, 'created' );
	}

	public function ttIdToTermId( $sourceTtId ) {
		return $this->intOrNull( $this->ledger->lookup( 'ttid_termid', $sourceTtId ) );
	}

	/* ---- Users ----------------------------------------------------------- */

	public function rememberUser( $sourceId, $destId, $status = 'created', $hash = null ) {
		$this->ledger->remember( 'user', $sourceId, $destId, $status, $hash );
	}

	public function user( $sourceId ) {
		return $this->intOrNull( $this->ledger->lookup( 'user', $sourceId ) );
	}

	/* ---- Comments -------------------------------------------------------- */

	public function rememberComment( $sourceId, $destId, $status = 'created', $hash = null ) {
		$this->ledger->remember( 'comment', $sourceId, $destId, $status, $hash );
	}

	public function comment( $sourceId ) {
		return $this->intOrNull( $this->ledger->lookup( 'comment', $sourceId ) );
	}

	/* ---- URLs (attachment source URL -> dest URL) ------------------------ */

	public function rememberUrl( $sourceUrl, $destUrl ) {
		$this->ledger->remember( 'url', $sourceUrl, $destUrl, 'created' );
	}

	/**
	 * @param string $sourceUrl
	 * @return string|null
	 */
	public function url( $sourceUrl ) {
		$rec = $this->ledger->lookup( 'url', $sourceUrl );
		return $rec ? (string) $rec['dest_id'] : null;
	}

	/**
	 * All url mappings as [sourceUrl => destUrl], for bulk content rewriting.
	 *
	 * @return array<string,string>
	 */
	public function allUrls() {
		return $this->ledger->all( 'url' );
	}

	/**
	 * Look up any previously-recorded mapping record (for conflict checks).
	 *
	 * @param string     $type
	 * @param int|string $sourceId
	 * @return array{dest_id:string,status:string,content_hash:?string}|null
	 */
	public function record( $type, $sourceId ) {
		return $this->ledger->lookup( $type, $sourceId );
	}

	/**
	 * @param array|null $rec
	 * @return int|null
	 */
	private function intOrNull( $rec ) {
		return $rec ? (int) $rec['dest_id'] : null;
	}
}
