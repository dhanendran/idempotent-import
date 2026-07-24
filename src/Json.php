<?php

namespace IdempotentImport;

/**
 * JSON helpers for reading the snapshot and writing the import report.
 *
 * Reading uses associative decoding with big-int-as-string safety.
 * Writing reuses the exporter's deterministic style (sorted keys, two-space
 * indent, one trailing newline) so the report diffs cleanly too.
 */
class Json {

	const ENCODE_FLAGS = JSON_UNESCAPED_UNICODE
		| JSON_UNESCAPED_SLASHES
		| JSON_INVALID_UTF8_SUBSTITUTE
		| JSON_THROW_ON_ERROR
		| JSON_PRETTY_PRINT;

	const DECODE_FLAGS = JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING;

	/**
	 * Decode a JSON string to an associative array.
	 *
	 * @param string $json
	 * @return mixed
	 * @throws \JsonException On malformed JSON.
	 */
	public static function decode( $json ) {
		return json_decode( $json, true, 512, self::DECODE_FLAGS );
	}

	/**
	 * Read and decode a JSON file.
	 *
	 * @param string $path
	 * @return mixed
	 * @throws \RuntimeException If the file cannot be read.
	 * @throws \JsonException    If the contents are not valid JSON.
	 */
	public static function readFile( $path ) {
		$raw = file_get_contents( $path );
		if ( false === $raw ) {
			throw new \RuntimeException( "Could not read file: {$path}" );
		}
		return self::decode( $raw );
	}

	/**
	 * Encode deterministically: recursively sorted object keys, list order
	 * preserved, two-space indent, single trailing newline.
	 *
	 * @param mixed $data
	 * @return string
	 * @throws \JsonException On encode failure.
	 */
	public static function encode( $data ) {
		$json = json_encode( self::sortKeys( $data ), self::ENCODE_FLAGS );
		$json = preg_replace_callback(
			'/^( +)/m',
			static function ( $m ) {
				return str_repeat( ' ', (int) ( strlen( $m[0] ) / 2 ) );
			},
			$json
		);
		return $json . "\n";
	}

	/**
	 * Recursively sort associative-array keys (case-sensitive ASCII) while
	 * preserving numerically-indexed list order.
	 *
	 * @param mixed $data
	 * @return mixed
	 */
	public static function sortKeys( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$isList = self::isList( $data );
		$out    = array();
		foreach ( $data as $k => $v ) {
			$out[ $k ] = self::sortKeys( $v );
		}
		if ( ! $isList ) {
			ksort( $out, SORT_STRING );
		}
		return $out;
	}

	/**
	 * @param array $arr
	 * @return bool
	 */
	private static function isList( array $arr ) {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $arr );
		}
		$i = 0;
		foreach ( $arr as $k => $_ ) {
			if ( $k !== $i++ ) {
				return false;
			}
		}
		return true;
	}
}
