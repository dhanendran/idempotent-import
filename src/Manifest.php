<?php

namespace IdempotentImport;

/**
 * Parses and lightly validates a snapshot's manifest.json.
 *
 * The importer understands the same schema the exporter emits. Major-version
 * mismatches are refused unless --force is given; the exporter's current
 * schema is 1.x.
 */
class Manifest {

	/** Highest exporter schema major version this importer understands. */
	const SUPPORTED_MAJOR = 1;

	/** @var array */
	private $data;

	/**
	 * @param array $data Decoded manifest.json contents.
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * @param string $path Path to manifest.json.
	 * @return self
	 * @throws \RuntimeException If the file is missing or malformed.
	 */
	public static function fromFile( $path ) {
		if ( ! is_file( $path ) ) {
			throw new \RuntimeException( "manifest.json not found at {$path}" );
		}
		$data = Json::readFile( $path );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'manifest.json did not decode to an object.' );
		}
		return new self( $data );
	}

	/**
	 * @return string
	 */
	public function schemaVersion() {
		return isset( $this->data['schema_version'] ) ? (string) $this->data['schema_version'] : '0.0.0';
	}

	/**
	 * Whether the manifest's schema major version is one we understand.
	 *
	 * @return bool
	 */
	public function isCompatible() {
		$parts = explode( '.', $this->schemaVersion() );
		$major = isset( $parts[0] ) ? (int) $parts[0] : 0;
		return $major === self::SUPPORTED_MAJOR;
	}

	/**
	 * @return array
	 */
	public function source() {
		return isset( $this->data['source'] ) && is_array( $this->data['source'] ) ? $this->data['source'] : array();
	}

	/**
	 * @return string
	 */
	public function sourceUrl() {
		$source = $this->source();
		return isset( $source['site_url'] ) ? (string) $source['site_url'] : '';
	}

	/**
	 * @return int|null
	 */
	public function sourceBlogId() {
		$source = $this->source();
		return isset( $source['blog_id'] ) && null !== $source['blog_id'] ? (int) $source['blog_id'] : null;
	}

	/**
	 * A stable identity for the source site, used to namespace the ledger.
	 *
	 * @return string
	 */
	public function sourceKey() {
		$blog = null === $this->sourceBlogId() ? '0' : (string) $this->sourceBlogId();
		return substr( hash( 'sha256', $this->sourceUrl() . '|' . $blog ), 0, 32 );
	}

	/**
	 * @return array<string,int>
	 */
	public function counts() {
		return isset( $this->data['counts'] ) && is_array( $this->data['counts'] ) ? $this->data['counts'] : array();
	}

	/**
	 * @param string $type
	 * @return int
	 */
	public function count( $type ) {
		$counts = $this->counts();
		return isset( $counts[ $type ] ) ? (int) $counts[ $type ] : 0;
	}

	/**
	 * @return array
	 */
	public function raw() {
		return $this->data;
	}
}
