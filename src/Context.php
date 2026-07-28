<?php

namespace IdempotentImport;

use IdempotentImport\Contracts\WordPress;

/**
 * Immutable bundle of shared services handed to every importer and every
 * extension point (resolvers, mappers, strategies, rewriters). Keeps their
 * signatures stable as the service set grows.
 */
class Context {

	/** @var WordPress */
	public $wp;

	/** @var IdMap */
	public $idMap;

	/** @var Config */
	public $config;

	/** @var Logger */
	public $logger;

	/** @var Decoder */
	public $decoder;

	/** @var Manifest */
	public $manifest;

	/** @var Report */
	public $report;

	/** @var string 'create'|'rewrite' — the current phase. */
	public $phase = 'create';

	/** @var string One of: update|skip|recreate. */
	public $onConflict = 'update';

	/** @var bool */
	public $dryRun = false;

	/** @var bool Emit a per-entity decision line for every entity. */
	public $verbose = false;

	/** @var bool Suppress progress bars. */
	public $quiet = false;

	/**
	 * @param WordPress $wp
	 * @param IdMap     $idMap
	 * @param Config    $config
	 * @param Logger    $logger
	 * @param Decoder   $decoder
	 * @param Manifest  $manifest
	 * @param Report    $report
	 */
	public function __construct(
		WordPress $wp,
		IdMap $idMap,
		Config $config,
		Logger $logger,
		Decoder $decoder,
		Manifest $manifest,
		Report $report
	) {
		$this->wp       = $wp;
		$this->idMap    = $idMap;
		$this->config   = $config;
		$this->logger   = $logger;
		$this->decoder  = $decoder;
		$this->manifest = $manifest;
		$this->report   = $report;
	}
}
