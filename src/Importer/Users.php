<?php

namespace IdempotentImport\Importer;

use IdempotentImport\Contracts\WordPress;
use IdempotentImport\UserDecision;

/**
 * Imports users.
 *
 * Create phase: for each source user, consult the ledger (idempotency), then the
 * UserMapper (remap/skip policy), then the resolver (existing destination user),
 * finally creating a new user with a random password. Records the source ->
 * destination id in the IdMap so posts and comments can rewrite authorship.
 *
 * Rewrite phase: writes full user meta for users created this run, and — because
 * accounts are network-global but roles are per-site — attaches just the per-blog
 * role for users that matched an existing account (so someone who belongs to
 * several source sites gets the right role on each destination subsite).
 */
class Users extends AbstractImporter {

	/** Source id => dest id for users matched to an existing account (attach role only). */
	protected $matchedIds = array();

	/** @var array<int,bool>|null Ledger users no longer belonging to this blog, resolved lazily. */
	protected $nonMembers = null;

	/** @var bool True when membership loss exceeds MISSING_LIMIT and every role is re-attached. */
	protected $nonMembersWholesale = false;

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
		$this->restoring = false;
		$hash            = $this->hash( $entity );
		$decision        = $this->ledgerDecision( 'user', $srcId, $hash );

		if ( 'new' !== $decision['state'] ) {
			$destId = (int) $decision['dest'];
			if ( ! $this->destinationIntact( 'user', $destId ) ) {
				$this->note( 'user', $srcId, "restoring: destination user #{$destId} no longer exists" );
				$this->restoring = true;
			} elseif ( 'unchanged' === $decision['state'] ) {
				if ( $this->isBlogMember( $destId, $entity ) ) {
					$this->note( 'user', $srcId, "unchanged #{$destId} (already imported, nothing to do)" );
					$this->ctx->report->record( 'user', 'unchanged' );
					return;
				}
				// The account survived; only its membership of this blog was removed.
				$this->matchedIds[ (string) $srcId ] = $destId;
				$this->note( 'user', $srcId, "restored #{$destId} (removed from this blog; re-attaching role)" );
				$this->ctx->report->record( 'user', 'restored' );
				return;
			}
		}
		if ( ! $this->restoring && 'changed' === $decision['state'] && 'update' === $this->ctx->onConflict ) {
			$this->writeIds[ (string) $srcId ] = (int) $decision['dest'];
			$this->ctx->idMap->rememberUser( $srcId, (int) $decision['dest'], 'updated', $hash );
			$this->note( 'user', $srcId, "updated #{$decision['dest']} (content changed)" );
			$this->ctx->report->record( 'user', 'updated' );
			return;
		}
		if ( ! $this->restoring && 'changed' === $decision['state'] ) {
			$this->note( 'user', $srcId, "conflict #{$decision['dest']} (source changed; kept destination, use --on-conflict=update)" );
			$this->ctx->report->record( 'user', 'conflict' );
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
			$this->matchedIds[ (string) $srcId ] = (int) $dec->destId;
			$this->note( 'user', $srcId, "matched #{$dec->destId} (config remap); attaching blog role" );
			$this->ctx->report->record( 'user', $this->outcome( 'matched' ) );
			return;
		}

		// CREATE: prefer an existing destination user before inserting.
		$existing = $this->resolveExisting( 'user', $entity );
		if ( $existing ) {
			$this->ctx->idMap->rememberUser( $srcId, $existing, 'matched', $hash );
			$this->matchedIds[ (string) $srcId ] = (int) $existing;
			$this->note( 'user', $srcId, "matched existing #{$existing} (by user_login/user_email); attaching blog role" );
			$this->ctx->report->record( 'user', $this->outcome( 'matched' ) );
			return;
		}

		if ( $this->ctx->dryRun ) {
			$this->note( 'user', $srcId, 'would create (no existing match)' );
			$this->ctx->report->record( 'user', $this->outcome( 'created' ) );
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
		$this->note( 'user', $srcId, "created #{$destId}" );
		$this->ctx->report->record( 'user', $this->outcome( 'created' ) );
		$this->fire( 'idempotent_import_after_entity', 'user', $entity, $destId );
	}

	public function rewritePhase() {
		if ( $this->ctx->dryRun || ( empty( $this->writeIds ) && empty( $this->matchedIds ) ) ) {
			return;
		}
		foreach ( $this->snapshot->iterate( 'users' ) as $entity ) {
			$srcId = isset( $entity['ID'] ) ? (string) $entity['ID'] : '';
			if ( '' === $srcId ) {
				continue;
			}
			if ( isset( $this->writeIds[ $srcId ] ) ) {
				$this->writeMeta(
					'user',
					$this->writeIds[ $srcId ],
					$entity,
					function ( $id, $key, $value ) {
						$this->ctx->wp->addUserMeta( $id, $key, $value );
					},
					function ( $id, $key ) {
						$this->ctx->wp->deleteUserMeta( $id, $key );
					}
				);
			} elseif ( isset( $this->matchedIds[ $srcId ] ) ) {
				$this->attachRole( $this->matchedIds[ $srcId ], $entity );
			}
		}
	}

	/**
	 * Does this account still belong to the destination blog?
	 *
	 * Accounts are network-global, so an existing user row does not mean the
	 * import is still in effect here: removing someone from a site deletes only
	 * their per-blog capabilities. Snapshots that grant no role have nothing to
	 * lose, so they count as members. One anti-join query, run lazily.
	 *
	 * @param int   $destId
	 * @param array $entity
	 * @return bool
	 */
	protected function isBlogMember( $destId, array $entity ) {
		if ( ! isset( $entity['meta']['wp_capabilities'] ) ) {
			return true;
		}
		if ( null === $this->nonMembers ) {
			$gone = $this->ctx->wp->nonMemberUserIds( $this->ctx->idMap->ledger() );
			if ( count( $gone ) > WordPress::MISSING_LIMIT ) {
				// Whole-blog membership loss: re-attach everyone rather than list them.
				$this->ctx->logger->warn( 'user', '*', 'more than ' . WordPress::MISSING_LIMIT . ' users are no longer members of this blog; re-attaching all roles' );
				$this->nonMembersWholesale = true;
			}
			$this->nonMembers = $this->nonMembersWholesale ? array() : array_fill_keys( array_map( 'intval', $gone ), true );
		}
		return ! $this->nonMembersWholesale && ! isset( $this->nonMembers[ (int) $destId ] );
	}

	/**
	 * Attach only the per-blog role (wp_capabilities / wp_user_level) to a matched
	 * account for the destination blog, leaving its global profile untouched. The
	 * gateway rebases these keys onto the destination blog's prefix, so importing
	 * a user's second site grants them their role there without a duplicate account.
	 *
	 * The snapshot's role wins: an account already holding a role on this blog is
	 * overwritten, so a destination Editor is downgraded if the snapshot says
	 * Subscriber. That is what a migration wants, but it is the one place matched
	 * content is not left authoritative — set `users.attach_roles_to_matched` to
	 * false to keep the destination's own roles instead.
	 *
	 * @param int   $destId
	 * @param array $entity
	 * @return void
	 */
	private function attachRole( $destId, array $entity ) {
		if ( ! $this->ctx->config->get( 'users.attach_roles_to_matched', true ) ) {
			return;
		}
		$meta = isset( $entity['meta'] ) && is_array( $entity['meta'] ) ? $entity['meta'] : array();
		$role = array_intersect_key( $meta, array_flip( array( 'wp_capabilities', 'wp_user_level' ) ) );
		if ( ! $role ) {
			return;
		}
		$this->writeMeta(
			'user',
			$destId,
			array( 'meta' => $role ),
			function ( $id, $key, $value ) {
				$this->ctx->wp->addUserMeta( $id, $key, $value );
			},
			function ( $id, $key ) {
				$this->ctx->wp->deleteUserMeta( $id, $key );
			}
		);
	}
}
