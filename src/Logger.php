<?php

namespace IdempotentImport;

/**
 * Collects skip/warn events during an import run. Mirrors the exporter's
 * Logger: one tab-separated line per event to report.log, plus a retained
 * skip list for the import report.
 *
 * Insertion order is preserved (no sorting), so a stable processing order
 * yields a stable log.
 */
class Logger {

	const SEV_SKIP = 'skip';
	const SEV_WARN = 'warn';

	/** @var string|null Path to report.log; null in dry-run. */
	private $logPath;

	/** @var resource|null */
	private $handle = null;

	/** @var array<int, array{type:string,id:string,reason:string}> */
	private $skips = array();

	/** @var int */
	private $warnCount = 0;

	/**
	 * @param string|null $logPath Null disables file writes (dry-run).
	 */
	public function __construct( $logPath = null ) {
		$this->logPath = $logPath;
	}

	public function open() {
		if ( null === $this->logPath ) {
			return;
		}
		$h = fopen( $this->logPath, 'wb' );
		if ( false === $h ) {
			throw new \RuntimeException( "Could not open {$this->logPath} for writing." );
		}
		$this->handle = $h;
	}

	public function close() {
		if ( is_resource( $this->handle ) ) {
			fclose( $this->handle );
			$this->handle = null;
		}
	}

	/**
	 * Record a skip: the entity was not imported.
	 *
	 * @param string     $type
	 * @param int|string $id
	 * @param string     $reason
	 */
	public function skip( $type, $id, $reason ) {
		$this->skips[] = array(
			'id'     => (string) $id,
			'reason' => $reason,
			'type'   => $type,
		);
		$this->write( self::SEV_SKIP, $type, $id, $reason );
	}

	/**
	 * Record a warning: the entity was imported, but something noteworthy happened.
	 *
	 * @param string     $type
	 * @param int|string $id
	 * @param string     $reason
	 */
	public function warn( $type, $id, $reason ) {
		++$this->warnCount;
		$this->write( self::SEV_WARN, $type, $id, $reason );
	}

	/**
	 * @return array<int, array{type:string,id:string,reason:string}>
	 */
	public function skips() {
		return $this->skips;
	}

	/**
	 * @return int
	 */
	public function skipCount() {
		return count( $this->skips );
	}

	/**
	 * @return int
	 */
	public function warnCount() {
		return $this->warnCount;
	}

	/**
	 * @param string     $severity
	 * @param string     $type
	 * @param int|string $id
	 * @param string     $reason
	 */
	private function write( $severity, $type, $id, $reason ) {
		if ( ! is_resource( $this->handle ) ) {
			return;
		}
		$line = sprintf(
			"%s\ttype=%s\tid=%s\t%s\n",
			$severity,
			$type,
			(string) $id,
			str_replace( array( "\n", "\r", "\t" ), ' ', $reason )
		);
		fwrite( $this->handle, $line );
	}
}
