<?php

namespace IdempotentImport;

/**
 * Reads an on-disk snapshot tree produced by the exporter.
 *
 * Streams entity files with a generator so a 2M-post snapshot never has to be
 * held in memory at once. Files are visited in sorted path order, which makes
 * a run's processing order (and therefore its report.log) deterministic.
 */
class Snapshot {

	/** @var string Absolute path to the snapshot root. */
	private $root;

	/**
	 * @param string $root
	 */
	public function __construct( $root ) {
		$this->root = rtrim( $root, '/\\' );
	}

	/**
	 * @return string
	 */
	public function root() {
		return $this->root;
	}

	/**
	 * @throws \RuntimeException If the root is not a readable directory.
	 */
	public function assertReadable() {
		if ( ! is_dir( $this->root ) ) {
			throw new \RuntimeException( "Snapshot directory not found: {$this->root}" );
		}
		if ( ! is_file( $this->root . '/manifest.json' ) ) {
			throw new \RuntimeException( "No manifest.json in snapshot: {$this->root}" );
		}
	}

	/**
	 * @return Manifest
	 */
	public function manifest() {
		return Manifest::fromFile( $this->root . '/manifest.json' );
	}

	/**
	 * Iterate decoded entity files under a subdirectory (posts, terms, users,
	 * comments). Yields [$relativePath => $decodedArray].
	 *
	 * Files that fail to decode are skipped here and reported by the caller via
	 * the returned malformed list; use iterateWithErrors() when you need them.
	 *
	 * @param string $subdir
	 * @return \Generator<string,array>
	 */
	public function iterate( $subdir ) {
		foreach ( $this->files( $subdir ) as $relative => $absolute ) {
			$data = $this->tryDecode( $absolute );
			if ( null === $data ) {
				continue;
			}
			yield $relative => $data;
		}
	}

	/**
	 * Like iterate(), but yields a third element flagging decode failures so the
	 * caller can log a skip. Yields [$relativePath, $decodedArrayOrNull, $error].
	 *
	 * @param string $subdir
	 * @return \Generator<int,array{0:string,1:?array,2:?string}>
	 */
	public function iterateWithErrors( $subdir ) {
		foreach ( $this->files( $subdir ) as $relative => $absolute ) {
			$error = null;
			$data  = null;
			try {
				$decoded = Json::readFile( $absolute );
				if ( is_array( $decoded ) ) {
					$data = $decoded;
				} else {
					$error = 'file did not decode to an object';
				}
			} catch ( \Throwable $e ) {
				$error = 'invalid JSON: ' . $e->getMessage();
			}
			yield array( $relative, $data, $error );
		}
	}

	/**
	 * The single options.json file, decoded. Empty array if absent.
	 *
	 * @return array
	 */
	public function options() {
		$path = $this->root . '/options.json';
		if ( ! is_file( $path ) ) {
			return array();
		}
		$data = $this->tryDecode( $path );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Whether the snapshot contains any files for the given subdir.
	 *
	 * @param string $subdir
	 * @return bool
	 */
	public function has( $subdir ) {
		return is_dir( $this->root . '/' . $subdir );
	}

	/**
	 * Sorted list of *.json files under a subdirectory as [relative => absolute].
	 *
	 * @param string $subdir
	 * @return array<string,string>
	 */
	private function files( $subdir ) {
		$base = $this->root . '/' . trim( $subdir, '/' );
		if ( ! is_dir( $base ) ) {
			return array();
		}
		$out  = array();
		$iter = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iter as $file ) {
			if ( ! $file->isFile() || 'json' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			$abs      = $file->getPathname();
			$relative = ltrim( substr( $abs, strlen( $this->root ) ), '/\\' );
			$out[ str_replace( '\\', '/', $relative ) ] = $abs;
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * @param string $absolute
	 * @return array|null
	 */
	private function tryDecode( $absolute ) {
		try {
			$data = Json::readFile( $absolute );
			return is_array( $data ) ? $data : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
