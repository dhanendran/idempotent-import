<?php

namespace IdempotentImport;

use IdempotentImport\Contracts\Ledger;

/**
 * In-memory ledger. Used by the test suite and as a fallback where no database
 * is available. Not persistent across runs.
 */
class ArrayLedger implements Ledger {

	/** @var array<string, array<string, array{dest_id:string,status:string,content_hash:?string}>> */
	private $data = array();

	/**
	 * {@inheritDoc}
	 */
	public function remember( $type, $sourceId, $destId, $status, $contentHash = null ) {
		$this->data[ $type ][ (string) $sourceId ] = array(
			'dest_id'      => (string) $destId,
			'status'       => (string) $status,
			'content_hash' => null === $contentHash ? null : (string) $contentHash,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function lookup( $type, $sourceId ) {
		$sourceId = (string) $sourceId;
		return isset( $this->data[ $type ][ $sourceId ] ) ? $this->data[ $type ][ $sourceId ] : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function sqlIdentity() {
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function all( $type ) {
		if ( ! isset( $this->data[ $type ] ) ) {
			return array();
		}
		$out = array();
		foreach ( $this->data[ $type ] as $sourceId => $rec ) {
			$out[ $sourceId ] = $rec['dest_id'];
		}
		return $out;
	}
}
