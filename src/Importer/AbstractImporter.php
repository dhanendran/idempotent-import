<?php

namespace IdempotentImport\Importer;

use IdempotentImport\Context;
use IdempotentImport\Contracts\EntityImporter;
use IdempotentImport\Json;
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
	 * Write an entity's meta through the full pipeline. Called in the rewrite
	 * phase for freshly created/updated entities.
	 *
	 * @param string   $type    post|term|user|comment
	 * @param int      $destId
	 * @param array    $entity
	 * @param callable $adder   fn(int $destId, string $key, mixed $value): void
	 * @return void
	 */
	protected function writeMeta( $type, $destId, array $entity, callable $adder ) {
		$meta = isset( $entity['meta'] ) && is_array( $entity['meta'] ) ? $entity['meta'] : array();

		$mapper = $this->registry->metaMapper();
		if ( $mapper ) {
			$meta = $mapper->mapKeys( $meta, $type, $this->ctx );
		}

		foreach ( $meta as $key => $values ) {
			$values = (array) $values;
			if ( $mapper ) {
				$values = $mapper->transformValues( $key, $values, $type, $this->ctx );
			}
			$context = "{$type}.meta.{$key}";
			foreach ( $values as $value ) {
				$value = $this->rewriteValue( $value, $context );
				$adder( $destId, $key, $this->ctx->decoder->forStorageValue( $value ) );
			}
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
