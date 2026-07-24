<?php

namespace IdempotentImport\Importer;

use IdempotentImport\UserDecision;

/**
 * Imports users.
 *
 * Create phase: for each source user, consult the ledger (idempotency), then the
 * UserMapper (remap/skip policy), then the resolver (existing destination user),
 * finally creating a new user with a random password. Records the source ->
 * destination id in the IdMap so posts and comments can rewrite authorship.
 *
 * Rewrite phase: writes user meta (roles/capabilities and everything else) for
 * users created this run.
 */
class Users extends AbstractImporter {

	public function type() {
		return 'user';
	}

	public function createPhase() {
		foreach ( $this->snapshot->iterate( 'users' ) as $entity ) {
			$srcId = isset( $entity['ID'] ) ? (int) $entity['ID'] : 0;
			if ( $srcId <= 0 ) {
				continue;
			}
			$this->fire( 'idempotent_import_before_entity', 'user', $entity );
			$this->createOne( $srcId, $entity );
		}
	}

	/**
	 * @param int   $srcId
	 * @param array $entity
	 */
	private function createOne( $srcId, array $entity ) {
		$hash     = $this->hash( $entity );
		$decision = $this->ledgerDecision( 'user', $srcId, $hash );

		if ( 'unchanged' === $decision['state'] ) {
			$this->ctx->report->record( 'user', 'matched' );
			return;
		}
		if ( 'changed' === $decision['state'] && 'update' === $this->ctx->onConflict ) {
			$this->writeIds[ (string) $srcId ] = (int) $decision['dest'];
			$this->ctx->idMap->rememberUser( $srcId, (int) $decision['dest'], 'updated', $hash );
			$this->ctx->report->record( 'user', 'updated' );
			return;
		}
		if ( 'changed' === $decision['state'] ) {
			$this->ctx->report->record( 'user', 'matched' );
			return;
		}

		$map = $this->registry->userMapper();
		$dec = $map ? $map->map( $entity, $this->ctx ) : UserDecision::create( $entity );

		if ( UserDecision::SKIP === $dec->action ) {
			$this->ctx->logger->skip( 'user', $srcId, 'skipped by user mapper' );
			$this->ctx->report->record( 'user', 'skipped' );
			return;
		}
		if ( UserDecision::REMAP === $dec->action || UserDecision::REUSE === $dec->action ) {
			$this->ctx->idMap->rememberUser( $srcId, (int) $dec->destId, 'matched', $hash );
			$this->ctx->report->record( 'user', 'matched' );
			return;
		}

		// CREATE: prefer an existing destination user before inserting.
		$existing = $this->resolveExisting( 'user', $entity );
		if ( $existing ) {
			$this->ctx->idMap->rememberUser( $srcId, $existing, 'matched', $hash );
			$this->ctx->report->record( 'user', 'matched' );
			return;
		}

		if ( $this->ctx->dryRun ) {
			$this->ctx->report->record( 'user', 'created' );
			return;
		}

		try {
			$destId = $this->ctx->wp->insertUser( $this->ctx->decoder->forStorageRow( $dec->data ) );
		} catch ( \Throwable $e ) {
			$this->ctx->logger->skip( 'user', $srcId, $e->getMessage() );
			$this->ctx->report->record( 'user', 'skipped' );
			return;
		}

		$this->ctx->idMap->rememberUser( $srcId, $destId, 'created', $hash );
		$this->writeIds[ (string) $srcId ] = $destId;
		$this->ctx->report->record( 'user', 'created' );
		$this->fire( 'idempotent_import_after_entity', 'user', $entity, $destId );
	}

	public function rewritePhase() {
		if ( $this->ctx->dryRun || empty( $this->writeIds ) ) {
			return;
		}
		foreach ( $this->snapshot->iterate( 'users' ) as $entity ) {
			$srcId = isset( $entity['ID'] ) ? (string) $entity['ID'] : '';
			if ( '' === $srcId || ! isset( $this->writeIds[ $srcId ] ) ) {
				continue;
			}
			$destId = $this->writeIds[ $srcId ];
			$this->writeMeta(
				'user',
				$destId,
				$entity,
				function ( $id, $key, $value ) {
					$this->ctx->wp->addUserMeta( $id, $key, $value );
				}
			);
		}
	}
}
