<?php

namespace IdempotentImport\Importer;

use IdempotentImport\Context;
use IdempotentImport\Contracts\EntityImporter;
use IdempotentImport\Contracts\WordPress;
use IdempotentImport\Json;
use IdempotentImport\Progress;
use IdempotentImport\Registry;
use IdempotentImport\Snapshot;

/**
 * Shared behaviour for the entity importers: snapshot access, the meta-writing
 * pipeline (mapper -> value transform -> reference rewrite -> store), content
 * hashing for change detection, and the ledger decision that makes the run
 * idempotent.
 *
 * Concrete importers implement createPhase() and rewritePhase().
 */
abstract class AbstractImporter implements EntityImporter {

	/** @var Snapshot */
	protected $snapshot;

	/** @var Context */
	protected $ctx;

	/** @var Registry */
	protected $registry;

	/**
	 * Source ids created (or updated) this run, mapped to their destination id.
	 * Only these get their meta / references written in the rewrite phase;
	 * entities matched to pre-existing destination content are left untouched.
	 *
	 * @var array<string,int>
	 */
	protected $writeIds = array();

	/**
	 * True while re-importing an entity that the ledger had recorded but that is
	 * no longer on the destination, so its outcome reports as `restored`.
	 *
	 * @var bool
	 */
	protected $restoring = false;

	/** @var array<string, array<int,bool>> Missing destination ids per type, resolved lazily. */
	protected $missingDestIds = array();

	/** @var array<string,bool> Types whose destination is gone wholesale (past MISSING_LIMIT). */
	protected $missingWholesale = array();

	/**
	 * @param Snapshot $snapshot
	 * @param Context  $ctx
	 * @param Registry $registry
	 */
	public function __construct( Snapshot $snapshot, Context $ctx, Registry $registry ) {
		$this->snapshot = $snapshot;
		$this->ctx      = $ctx;
		$this->registry = $registry;
	}

	/**
	 * A stable content hash for an entity, used to detect changes on re-import.
	 *
	 * @param array $entity
	 * @return string
	 */
	protected function hash( array $entity ) {
		try {
			return hash( 'sha256', Json::encode( $entity ) );
		} catch ( \Throwable $e ) {
			return hash( 'sha256', serialize( $entity ) );
		}
	}

	/**
	 * Consult the ledger for a prior import of this entity.
	 *
	 * @param string     $type
	 * @param int|string $sourceId
	 * @param string     $hash
	 * @return array{state:string,dest:?int}
	 *   state: new | unchanged | changed
	 */
	protected function ledgerDecision( $type, $sourceId, $hash ) {
		$rec = $this->ctx->idMap->record( $type, $sourceId );
		if ( ! $rec ) {
			return array(
				'state' => 'new',
				'dest'  => null,
			);
		}
		$dest      = (int) $rec['dest_id'];
		$unchanged = ( null !== $rec['content_hash'] && $rec['content_hash'] === $hash );
		return array(
			'state' => $unchanged ? 'unchanged' : 'changed',
			'dest'  => $dest,
		);
	}

	/**
	 * Is the destination entity a ledger row points at still there?
	 *
	 * A ledger hit only proves the importer did the work once; it says nothing
	 * about the destination now. Without this check anything deleted outside the
	 * importer is skipped on every subsequent run and never comes back.
	 *
	 * One query per entity type per run, and it returns only what went missing —
	 * usually nothing — so the cost does not grow with the size of the migration.
	 *
	 * @param string $type   user|post|term|comment
	 * @param int    $destId
	 * @return bool
	 */
	protected function destinationIntact( $type, $destId ) {
		if ( ! isset( $this->missingDestIds[ $type ] ) ) {
			$missing   = $this->ctx->wp->missingDestIds( $type, $this->ctx->idMap->ledger() );
			$wholesale = count( $missing ) > WordPress::MISSING_LIMIT;
			if ( $wholesale ) {
				// The destination was wiped, not edited. Treat the type as gone rather
				// than hold millions of ids; every entity re-imports anyway.
				$this->ctx->logger->warn( $type, '*', 'destination missing more than ' . WordPress::MISSING_LIMIT . " {$type}s; treating all as missing" );
			}
			$this->missingWholesale[ $type ] = $wholesale;
			$this->missingDestIds[ $type ]   = $wholesale ? array() : array_fill_keys( array_map( 'intval', $missing ), true );
		}
		if ( ! empty( $this->missingWholesale[ $type ] ) ) {
			return false;
		}
		return ! isset( $this->missingDestIds[ $type ][ (int) $destId ] );
	}

	/**
	 * The outcome to report, upgraded to `restored` when this entity is being
	 * re-imported because it had vanished from the destination.
	 *
	 * @param string $natural
	 * @return string
	 */
	protected function outcome( $natural ) {
		return $this->restoring ? 'restored' : $natural;
	}

	/**
	 * Write an entity's meta through the full pipeline. Called in the rewrite
	 * phase for freshly created/updated entities.
	 *
	 * Existing rows for each key are cleared first (via $deleter) so meta is
	 * replaced, not appended — otherwise the defaults WordPress seeds on insert
	 * (e.g. a user's subscriber wp_capabilities) would win over the snapshot, and
	 * re-imports would accumulate duplicate rows.
	 *
	 * Keys are slashed alongside values: add_metadata() and delete_metadata() both
	 * unslash the key, so a key containing a backslash arrives mangled otherwise.
	 *
	 * @param string        $type      post|term|user|comment
	 * @param int           $destId
	 * @param array         $entity
	 * @param callable      $adder     fn(int $destId, string $key, mixed $value): void
	 * @param callable|null $deleter   fn(int $destId, string $key): void
	 * @param string[]|null $destKeys  Keys currently on the destination. Any not in
	 *                                 the snapshot are deleted; null to leave them.
	 * @return void
	 */
	protected function writeMeta( $type, $destId, array $entity, callable $adder, ?callable $deleter = null, ?array $destKeys = null ) {
		$meta = isset( $entity['meta'] ) && is_array( $entity['meta'] ) ? $entity['meta'] : array();

		$mapper = $this->registry->metaMapper();
		if ( $mapper ) {
			$meta = $mapper->mapKeys( $meta, $type, $this->ctx );
		}

		if ( null !== $destKeys && $deleter ) {
			foreach ( array_diff( $destKeys, array_map( 'strval', array_keys( $meta ) ) ) as $stale ) {
				$deleter( $destId, $this->ctx->decoder->forStorageValue( $stale ) );
			}
		}

		foreach ( $meta as $key => $values ) {
			$values = (array) $values;
			if ( $mapper ) {
				$values = $mapper->transformValues( $key, $values, $type, $this->ctx );
			}
			$storedKey = $this->ctx->decoder->forStorageValue( (string) $key );
			if ( $deleter ) {
				$deleter( $destId, $storedKey );
			}
			$context = "{$type}.meta.{$key}";
			foreach ( $values as $value ) {
				$value = $this->rewriteValue( $value, $context );
				$adder( $destId, $storedKey, $this->ctx->decoder->forStorageValue( $value ) );
			}
		}
	}

	/**
	 * Iterate a snapshot subdirectory behind a progress bar for the current phase.
	 *
	 * Both phases walk the same set, so each importer draws two bars over a run.
	 * The bar is sized from the manifest rather than by counting files, which would
	 * mean a second walk of the tree before any work could start.
	 *
	 * @param string $subdir posts|users|terms|comments
	 * @return \Generator<string,array>
	 */
	protected function each( $subdir ) {
		$verb = 'rewrite' === $this->ctx->phase ? 'Rewriting' : 'Importing';
		$bar  = new Progress(
			"{$verb} {$subdir}",
			$this->ctx->manifest->count( $subdir ),
			empty( $this->ctx->quiet ) && empty( $this->ctx->verbose )
		);
		try {
			foreach ( $this->snapshot->iterate( $subdir ) as $relative => $entity ) {
				yield $relative => $entity;
				$bar->tick();
			}
		} finally {
			$bar->finish();
		}
	}

	/**
	 * Emit a per-entity decision line when --verbose is set (console + report.log).
	 *
	 * @param string     $type
	 * @param int|string $sourceId
	 * @param string     $message
	 * @return void
	 */
	protected function note( $type, $sourceId, $message ) {
		if ( ! empty( $this->ctx->verbose ) ) {
			$this->ctx->logger->info( $type, $sourceId, $message );
		}
	}

	/**
	 * Run a single value through every registered reference rewriter that
	 * handles the given context.
	 *
	 * @param mixed  $value
	 * @param string $context
	 * @return mixed
	 */
	protected function rewriteValue( $value, $context ) {
		foreach ( $this->registry->referenceRewriters() as $rewriter ) {
			if ( $rewriter->handles( $context, $this->ctx ) ) {
				$value = $rewriter->rewrite( $value, $context, $this->ctx );
			}
		}
		if ( function_exists( 'apply_filters' ) ) {
			$value = apply_filters( 'idempotent_import_rewrite_value', $value, $context, $this->ctx->idMap );
		}
		return $value;
	}

	/**
	 * Resolve an incoming entity to an existing destination id, applying the
	 * registered resolver and the `idempotent_import_resolve_{type}` filter.
	 *
	 * @param string $type
	 * @param array  $entity
	 * @return int|null
	 */
	protected function resolveExisting( $type, array $entity ) {
		$resolver = $this->registry->resolver( $type );
		$dest     = $resolver ? $resolver->resolve( $entity, $this->ctx ) : null;
		if ( function_exists( 'apply_filters' ) ) {
			$dest = apply_filters( "idempotent_import_resolve_{$type}", $dest, $entity, $this->ctx );
		}
		return $dest ? (int) $dest : null;
	}

	/**
	 * Emit lifecycle actions if WordPress is loaded (no-op in tests).
	 *
	 * @param string $hook
	 * @param mixed  ...$args
	 * @return void
	 */
	protected function fire( $hook, ...$args ) {
		if ( function_exists( 'do_action' ) ) {
			do_action( $hook, ...$args );
		}
	}
}
