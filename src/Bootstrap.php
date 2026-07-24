<?php

namespace IdempotentImport;

use IdempotentImport\Attachment\MapToExisting;
use IdempotentImport\Attachment\ReferenceOnly;
use IdempotentImport\Attachment\Sideload;
use IdempotentImport\Attachment\Skip;
use IdempotentImport\Mapper\DefaultMetaMapper;
use IdempotentImport\Mapper\DefaultUserMapper;
use IdempotentImport\Resolver\CommentResolver;
use IdempotentImport\Resolver\PostResolver;
use IdempotentImport\Resolver\TermResolver;
use IdempotentImport\Resolver\UserResolver;
use IdempotentImport\Rewriter\CoreReferenceRewriter;
use IdempotentImport\Rewriter\DefaultContentRewriter;

/**
 * Builds the Registry pre-loaded with the default resolvers, mappers,
 * attachment strategies and rewriters. Projects extend or replace any of these
 * by hooking `idempotent_import_register`, which fires after the defaults are in
 * place.
 */
class Bootstrap {

	/**
	 * @return Registry
	 */
	public static function defaultRegistry() {
		$registry = new Registry();

		$registry->registerResolver( 'user', new UserResolver() );
		$registry->registerResolver( 'term', new TermResolver() );
		$registry->registerResolver( 'post', new PostResolver() );
		$registry->registerResolver( 'comment', new CommentResolver() );

		$registry->registerUserMapper( new DefaultUserMapper() );
		$registry->registerMetaMapper( new DefaultMetaMapper() );

		$registry->registerAttachmentStrategy( 'sideload', new Sideload() );
		$registry->registerAttachmentStrategy( 'reference', new ReferenceOnly() );
		$registry->registerAttachmentStrategy( 'map-existing', new MapToExisting() );
		$registry->registerAttachmentStrategy( 'skip', new Skip() );

		$registry->registerReferenceRewriter( new CoreReferenceRewriter() );
		$registry->registerContentRewriter( new DefaultContentRewriter() );

		return $registry;
	}

	/**
	 * Let projects register their own extensions. Passing the registry through a
	 * filter keeps this a no-op when WordPress is not loaded (tests).
	 *
	 * @param Registry $registry
	 * @param Context  $ctx
	 * @return Registry
	 */
	public static function applyProjectExtensions( Registry $registry, Context $ctx ) {
		if ( function_exists( 'do_action' ) ) {
			do_action( 'idempotent_import_register', $registry, $ctx );
		}
		return $registry;
	}
}
