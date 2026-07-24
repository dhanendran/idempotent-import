<?php

namespace IdempotentImport;

/**
 * Builds import-report.json, the importer's analogue of the exporter's
 * manifest.json. Records per-type outcome counts (created / matched / updated /
 * skipped), the resolved source key, timing, and the sorted skip list, and
 * writes the human-facing report.log alongside it.
 */
class Report {

	const SCHEMA_VERSION = '1.0.0';

	/** @var array<string, array<string,int>> */
	private $outcomes = array();

	/** @var string */
	private $sourceKey = '';

	/** @var array */
	private $manifestSource = array();

	/** @var array */
	private $options = array();

	/**
	 * @param string $sourceKey
	 * @param array  $manifestSource
	 * @param array  $options Flags in effect for this run (for provenance).
	 */
	public function __construct( $sourceKey, array $manifestSource, array $options ) {
		$this->sourceKey      = $sourceKey;
		$this->manifestSource = $manifestSource;
		$this->options        = $options;
	}

	/**
	 * Record an outcome for one entity.
	 *
	 * @param string $type    post|term|user|comment|option|attachment
	 * @param string $outcome created|matched|updated|skipped
	 */
	public function record( $type, $outcome ) {
		if ( ! isset( $this->outcomes[ $type ] ) ) {
			$this->outcomes[ $type ] = array(
				'created' => 0,
				'matched' => 0,
				'skipped' => 0,
				'updated' => 0,
			);
		}
		if ( ! isset( $this->outcomes[ $type ][ $outcome ] ) ) {
			$this->outcomes[ $type ][ $outcome ] = 0;
		}
		++$this->outcomes[ $type ][ $outcome ];
	}

	/**
	 * @return array<string, array<string,int>>
	 */
	public function outcomes() {
		return $this->outcomes;
	}

	/**
	 * @param array $skips From the Logger.
	 * @return array
	 */
	public function build( array $skips ) {
		usort(
			$skips,
			static function ( $a, $b ) {
				$cmp = strcmp( (string) $a['type'], (string) $b['type'] );
				if ( 0 !== $cmp ) {
					return $cmp;
				}
				return strnatcmp( (string) $a['id'], (string) $b['id'] );
			}
		);

		return array(
			'imported_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'options'        => $this->options,
			'outcomes'       => $this->outcomes,
			'schema_version' => self::SCHEMA_VERSION,
			'skipped'        => $skips,
			'source'         => $this->manifestSource,
			'source_key'     => $this->sourceKey,
		);
	}
}
