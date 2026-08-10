<?php

namespace IdempotentImport;

/**
 * The inverse of the exporter's Encoder.
 *
 * The exporter writes stored strings to JSON verbatim, because $wpdb hands back
 * raw column bytes rather than slashed input. WordPress's insert/update APIs
 * (wp_insert_post, wp_insert_term, wp_insert_comment, wp_insert_user,
 * add_*_meta, update_option) all expect *slashed* input and un-slash it
 * internally, so we slash on the way in and land the original bytes. Never
 * un-slash on export to pair with this: stripslashes() is not the inverse of
 * addslashes() for data that legitimately contains backslashes: a footnotes
 * meta value's JSON escapes come back stripped, leaving unparseable JSON.
 *
 * Meta and option values decoded from JSON may be scalars or arrays. WordPress
 * re-serializes arrays on storage, so we simply hand arrays back (slashed
 * recursively); the exporter's unserialize step is inverted for free.
 */
class Decoder {

	/**
	 * Slash a full row of column => value pairs for a WP insert call.
	 *
	 * @param array $row
	 * @return array
	 */
	public function forStorageRow( array $row ) {
		return $this->slash( $row );
	}

	/**
	 * Prepare a single meta / option value for storage.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public function forStorageValue( $value ) {
		return $this->slash( $value );
	}

	/**
	 * Recursively wp_slash strings; leave numbers, booleans and null untouched.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private function slash( $value ) {
		if ( is_string( $value ) ) {
			return function_exists( 'wp_slash' ) ? wp_slash( $value ) : addslashes( $value );
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = $this->slash( $v );
			}
			return $out;
		}
		return $value;
	}
}
