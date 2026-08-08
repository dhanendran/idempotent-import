<?php

namespace IdempotentImport;

use IdempotentImport\Contracts\WordPress;

/**
 * Checks that the binaries behind a snapshot's attachments are present on the
 * destination.
 *
 * Binary media is not part of a snapshot — the exporter records attachment URLs
 * and metadata only, and the files travel separately (a folder copy locally, the
 * Files API on VIP). Nothing in an import run notices when that copy is partial:
 * the attachment records import cleanly, counts still reconcile against the
 * manifest, and the gap only surfaces as broken images on the rendered page.
 * This pass is that missing check, run against the same snapshot the import used.
 *
 * Registered sizes are checked as well as the original, because a missing
 * -1024x768 variant leaves srcset serving a 404 while the page still looks right
 * at full width.
 */
class MediaVerifier {

	/** @var Snapshot */
	private $snapshot;

	/** @var WordPress */
	private $wp;

	/** @var Logger */
	private $logger;

	/**
	 * @param Snapshot  $snapshot
	 * @param WordPress $wp
	 * @param Logger    $logger
	 */
	public function __construct( Snapshot $snapshot, WordPress $wp, Logger $logger ) {
		$this->snapshot = $snapshot;
		$this->wp       = $wp;
		$this->logger   = $logger;
	}

	/**
	 * Walk the snapshot's attachments and tally what is on disk.
	 *
	 * A missing original is a skip (it breaks the image outright, and skips drive
	 * the non-zero exit that gates cutover); a missing size is a warning.
	 *
	 * @return array{attachments:int,files:int,files_missing:int,sizes:int,sizes_missing:int,unlocatable:int}
	 */
	public function verify() {
		$result = array(
			'attachments'   => 0,
			'files'         => 0,
			'files_missing' => 0,
			'sizes'         => 0,
			'sizes_missing' => 0,
			'unlocatable'   => 0,
		);

		foreach ( $this->snapshot->iterate( 'posts' ) as $entity ) {
			if ( ! isset( $entity['post_type'] ) || 'attachment' !== $entity['post_type'] ) {
				continue;
			}
			++$result['attachments'];
			$srcId = isset( $entity['ID'] ) ? (int) $entity['ID'] : 0;

			$file = $this->attachedFile( $entity );
			if ( '' === $file ) {
				++$result['unlocatable'];
				$this->logger->warn( 'attachment', $srcId, 'no _wp_attached_file in the snapshot: its file cannot be located' );
				continue;
			}

			++$result['files'];
			if ( ! $this->wp->mediaFileExists( $file ) ) {
				++$result['files_missing'];
				$this->logger->skip( 'attachment', $srcId, "missing file: {$file}" );
			}

			foreach ( $this->sizeFiles( $entity, $file ) as $sizeFile ) {
				++$result['sizes'];
				if ( ! $this->wp->mediaFileExists( $sizeFile ) ) {
					++$result['sizes_missing'];
					$this->logger->warn( 'attachment', $srcId, "missing size: {$sizeFile}" );
				}
			}
		}

		return $result;
	}

	/**
	 * The attachment's own file, as a path relative to the uploads base.
	 *
	 * @param array $entity
	 * @return string Empty when the snapshot records no path.
	 */
	private function attachedFile( array $entity ) {
		$file = $this->metaValue( $entity, '_wp_attached_file' );
		return is_string( $file ) ? ltrim( $file, '/' ) : '';
	}

	/**
	 * Every generated size beside the original, as uploads-relative paths.
	 *
	 * Size entries carry a bare filename, which belongs to the original's own
	 * directory — the year/month folder, not the uploads root.
	 *
	 * @param array  $entity
	 * @param string $file The original's uploads-relative path.
	 * @return string[]
	 */
	private function sizeFiles( array $entity, $file ) {
		$meta = $this->metaValue( $entity, '_wp_attachment_metadata' );
		if ( ! is_array( $meta ) ) {
			return array();
		}

		$dir    = str_replace( '\\', '/', dirname( $file ) );
		$prefix = ( '.' === $dir || '' === $dir ) ? '' : trim( $dir, '/' ) . '/';
		$files  = array();

		if ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( is_array( $size ) && isset( $size['file'] ) && '' !== $size['file'] ) {
					$files[] = $prefix . (string) $size['file'];
				}
			}
		}

		// The untouched upload WordPress keeps beside a scaled original (5.3+).
		if ( isset( $meta['original_image'] ) && '' !== $meta['original_image'] ) {
			$files[] = $prefix . (string) $meta['original_image'];
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * First value of a meta key, in the export's uniform {key: [values]} shape.
	 *
	 * @param array  $entity
	 * @param string $key
	 * @return mixed Null when absent.
	 */
	private function metaValue( array $entity, $key ) {
		if ( ! isset( $entity['meta'][ $key ] ) || ! is_array( $entity['meta'][ $key ] ) ) {
			return null;
		}
		return isset( $entity['meta'][ $key ][0] ) ? $entity['meta'][ $key ][0] : null;
	}
}
