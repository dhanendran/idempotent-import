<?php

namespace IdempotentImport;

/**
 * The declarative import-map. Loaded from a .php file (returning an array) or a
 * .json file, then merged over sensible defaults and any CLI overrides.
 *
 * This is the zero-code extension layer: meta-key renames, user remaps,
 * attachment strategy and match rules all live here. For anything the config
 * cannot express, projects register classes on the Registry or hook the
 * documented filters.
 */
class Config {

	/** @var array */
	private $data;

	/**
	 * @param array $data
	 */
	public function __construct( array $data = array() ) {
		$this->data = self::mergeDeep( self::defaults(), $data );
	}

	/**
	 * Load from a file path. .php must `return array(...)`; .json is decoded.
	 *
	 * @param string $path
	 * @return self
	 * @throws \RuntimeException On unreadable / invalid config.
	 */
	public static function fromFile( $path ) {
		if ( ! is_file( $path ) ) {
			throw new \RuntimeException( "Config file not found: {$path}" );
		}
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( 'php' === $ext ) {
			$data = require $path;
		} elseif ( 'json' === $ext ) {
			$data = Json::readFile( $path );
		} else {
			throw new \RuntimeException( "Unsupported config extension: {$ext} (use .php or .json)" );
		}
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Config file did not return an array.' );
		}
		return new self( $data );
	}

	/**
	 * Built-in safe defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'users'       => array(
				'match_by'       => array( 'user_login', 'user_email' ),
				'remap'          => array(),
				'role_map'       => array(),
				'on_missing'     => 'create',
				'default_author' => 1,
			),
			'terms'       => array(
				'match_by' => array( 'taxonomy', 'slug' ),
			),
			'posts'       => array(
				'match_by'   => array( 'post_type', 'post_name' ),
				'status_map' => array(),
			),
			'comments'    => array(
				'match_by' => array( 'comment_post_ID', 'comment_author_email', 'comment_date_gmt' ),
			),
			'attachments' => array(
				'strategy' => 'sideload',
				'dedupe'   => 'source_url',
			),
			'meta'        => array(
				// Per entity type: rename{}, drop[], numeric[], refs{key => type}.
			),
			'options'     => array(
				'mode'  => 'allowlist',
				'allow' => array( 'blogname', 'blogdescription', 'date_format', 'time_format', 'start_of_week' ),
				'deny'  => array( 'cron', 'active_plugins', 'siteurl', 'home' ),
			),
		);
	}

	/**
	 * Apply CLI overrides that map onto config keys.
	 *
	 * @param array $assocArgs
	 * @return self
	 */
	public function applyCliOverrides( array $assocArgs ) {
		if ( isset( $assocArgs['attachments'] ) ) {
			$this->data['attachments']['strategy'] = (string) $assocArgs['attachments'];
		}
		if ( isset( $assocArgs['default-author'] ) ) {
			$this->data['users']['default_author'] = (int) $assocArgs['default-author'];
		}
		if ( isset( $assocArgs['options'] ) ) {
			$this->data['options']['mode'] = (string) $assocArgs['options'];
		}
		return $this;
	}

	/**
	 * Fetch a config section as an array.
	 *
	 * @param string $name
	 * @return array
	 */
	public function section( $name ) {
		return isset( $this->data[ $name ] ) && is_array( $this->data[ $name ] ) ? $this->data[ $name ] : array();
	}

	/**
	 * Dot-path getter, e.g. get('users.default_author', 1).
	 *
	 * @param string $path
	 * @param mixed  $default
	 * @return mixed
	 */
	public function get( $path, $default = null ) {
		$node = $this->data;
		foreach ( explode( '.', $path ) as $key ) {
			if ( is_array( $node ) && array_key_exists( $key, $node ) ) {
				$node = $node[ $key ];
			} else {
				return $default;
			}
		}
		return $node;
	}

	/**
	 * Meta rules for a given entity type.
	 *
	 * @param string $type
	 * @return array{rename:array,drop:array,numeric:array,refs:array}
	 */
	public function metaRules( $type ) {
		$rules = $this->get( "meta.{$type}", array() );
		return array(
			'rename'  => isset( $rules['rename'] ) ? (array) $rules['rename'] : array(),
			'drop'    => isset( $rules['drop'] ) ? (array) $rules['drop'] : array(),
			'numeric' => isset( $rules['numeric'] ) ? (array) $rules['numeric'] : array(),
			'refs'    => isset( $rules['refs'] ) ? (array) $rules['refs'] : array(),
		);
	}

	/**
	 * @return array
	 */
	public function toArray() {
		return $this->data;
	}

	/**
	 * Recursive array merge where later scalar/indexed values win, associative
	 * keys are merged.
	 *
	 * @param array $base
	 * @param array $over
	 * @return array
	 */
	private static function mergeDeep( array $base, array $over ) {
		foreach ( $over as $k => $v ) {
			if ( is_array( $v ) && isset( $base[ $k ] ) && is_array( $base[ $k ] ) && self::isAssoc( $v ) ) {
				$base[ $k ] = self::mergeDeep( $base[ $k ], $v );
			} else {
				$base[ $k ] = $v;
			}
		}
		return $base;
	}

	/**
	 * @param array $arr
	 * @return bool
	 */
	private static function isAssoc( array $arr ) {
		if ( array() === $arr ) {
			return false;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}
}
