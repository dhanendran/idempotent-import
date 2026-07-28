<?php

namespace IdempotentImport;

use IdempotentImport\Contracts\Ledger;

/**
 * $wpdb-backed ledger. Stores mappings in a dedicated custom table so the
 * old->new id map stays queryable at 2M-row scale without polluting postmeta.
 *
 * Table (created by ensureTable(), typically on plugin activation):
 *
 *   {$prefix}idempotent_import_map
 *     id           BIGINT UNSIGNED PK AUTO_INCREMENT
 *     source_key   VARCHAR(32)      -- Manifest::sourceKey()
 *     entity_type  VARCHAR(20)
 *     source_id    VARCHAR(191)
 *     dest_id      VARCHAR(191)
 *     content_hash CHAR(64) NULL
 *     status       VARCHAR(20)
 *     updated_at   DATETIME
 *     UNIQUE KEY src (source_key, entity_type, source_id)
 */
class DbLedger implements Ledger {

	/** @var \wpdb */
	private $wpdb;

	/** @var string */
	private $table;

	/** @var string */
	private $sourceKey;

	/** @var bool|null Cached table-existence check; see exists(). */
	private $exists = null;

	/**
	 * @param \wpdb  $wpdb
	 * @param string $sourceKey
	 */
	public function __construct( $wpdb, $sourceKey ) {
		$this->wpdb      = $wpdb;
		$this->table     = $wpdb->prefix . 'idempotent_import_map';
		$this->sourceKey = $sourceKey;
	}

	/**
	 * Is the ledger table there? A dry run deliberately does not create it, so
	 * every read has to tolerate its absence rather than raise a DB error per
	 * entity. Resolved once per run.
	 *
	 * @return bool
	 */
	private function exists() {
		if ( null === $this->exists ) {
			$this->exists = (bool) $this->wpdb->get_var(
				$this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table )
			);
		}
		return $this->exists;
	}

	/**
	 * Create the ledger table if it does not exist. Safe to call repeatedly.
	 *
	 * @return void
	 */
	public function ensureTable() {
		$charset = method_exists( $this->wpdb, 'get_charset_collate' ) ? $this->wpdb->get_charset_collate() : '';
		$sql     = "CREATE TABLE IF NOT EXISTS {$this->table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_key VARCHAR(32) NOT NULL,
			entity_type VARCHAR(20) NOT NULL,
			source_id VARCHAR(191) NOT NULL,
			dest_id VARCHAR(191) NOT NULL,
			content_hash CHAR(64) NULL,
			status VARCHAR(20) NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY src (source_key, entity_type, source_id)
		) {$charset};";
		$this->wpdb->query( $sql );
		$this->exists = true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function remember( $type, $sourceId, $destId, $status, $contentHash = null ) {
		$now = gmdate( 'Y-m-d H:i:s' );
		// Upsert: rely on the UNIQUE key. REPLACE would churn the PK; use
		// INSERT ... ON DUPLICATE KEY UPDATE via $wpdb->query with prepare.
		$sql = $this->wpdb->prepare(
			"INSERT INTO {$this->table}
				(source_key, entity_type, source_id, dest_id, content_hash, status, updated_at)
			 VALUES (%s, %s, %s, %s, %s, %s, %s)
			 ON DUPLICATE KEY UPDATE
				dest_id = VALUES(dest_id),
				content_hash = VALUES(content_hash),
				status = VALUES(status),
				updated_at = VALUES(updated_at)",
			$this->sourceKey,
			$type,
			(string) $sourceId,
			(string) $destId,
			null === $contentHash ? '' : (string) $contentHash,
			$status,
			$now
		);
		$this->wpdb->query( $sql );
	}

	/**
	 * {@inheritDoc}
	 */
	public function lookup( $type, $sourceId ) {
		if ( ! $this->exists() ) {
			return null;
		}
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT dest_id, status, content_hash FROM {$this->table}
				 WHERE source_key = %s AND entity_type = %s AND source_id = %s",
				$this->sourceKey,
				$type,
				(string) $sourceId
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		return array(
			'dest_id'      => (string) $row['dest_id'],
			'status'       => (string) $row['status'],
			'content_hash' => '' === $row['content_hash'] ? null : (string) $row['content_hash'],
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function sqlIdentity() {
		if ( ! $this->exists() ) {
			return null;
		}
		return array(
			'source_key' => $this->sourceKey,
			'table'      => $this->table,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function all( $type ) {
		if ( ! $this->exists() ) {
			return array();
		}
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT source_id, dest_id FROM {$this->table}
				 WHERE source_key = %s AND entity_type = %s",
				$this->sourceKey,
				$type
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r['source_id'] ] = (string) $r['dest_id'];
		}
		return $out;
	}
}
