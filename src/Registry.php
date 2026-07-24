<?php

namespace IdempotentImport;

use IdempotentImport\Contracts\AttachmentStrategy;
use IdempotentImport\Contracts\ContentRewriter;
use IdempotentImport\Contracts\MetaMapper;
use IdempotentImport\Contracts\ReferenceRewriter;
use IdempotentImport\Contracts\Resolver;
use IdempotentImport\Contracts\UserMapper;

/**
 * Holds the pluggable pieces of the importer. Ships with defaults; projects
 * override by registering their own implementations (or via the documented
 * filters, which the defaults consult).
 */
class Registry {

	/** @var array<string,Resolver> Keyed by entity type. */
	private $resolvers = array();

	/** @var UserMapper|null */
	private $userMapper;

	/** @var MetaMapper|null */
	private $metaMapper;

	/** @var array<string,AttachmentStrategy> Keyed by strategy name. */
	private $attachmentStrategies = array();

	/** @var ReferenceRewriter[] */
	private $referenceRewriters = array();

	/** @var ContentRewriter|null */
	private $contentRewriter;

	/* ---- Resolvers ------------------------------------------------------- */

	public function registerResolver( $type, Resolver $resolver ) {
		$this->resolvers[ $type ] = $resolver;
		return $this;
	}

	/**
	 * @param string $type
	 * @return Resolver|null
	 */
	public function resolver( $type ) {
		return isset( $this->resolvers[ $type ] ) ? $this->resolvers[ $type ] : null;
	}

	/* ---- User mapper ----------------------------------------------------- */

	public function registerUserMapper( UserMapper $mapper ) {
		$this->userMapper = $mapper;
		return $this;
	}

	/**
	 * @return UserMapper|null
	 */
	public function userMapper() {
		return $this->userMapper;
	}

	/* ---- Meta mapper ----------------------------------------------------- */

	public function registerMetaMapper( MetaMapper $mapper ) {
		$this->metaMapper = $mapper;
		return $this;
	}

	/**
	 * @return MetaMapper|null
	 */
	public function metaMapper() {
		return $this->metaMapper;
	}

	/* ---- Attachment strategies ------------------------------------------- */

	public function registerAttachmentStrategy( $name, AttachmentStrategy $strategy ) {
		$this->attachmentStrategies[ $name ] = $strategy;
		return $this;
	}

	/**
	 * @param string $name
	 * @return AttachmentStrategy|null
	 */
	public function attachmentStrategy( $name ) {
		return isset( $this->attachmentStrategies[ $name ] ) ? $this->attachmentStrategies[ $name ] : null;
	}

	/* ---- Reference rewriters --------------------------------------------- */

	public function registerReferenceRewriter( ReferenceRewriter $rewriter ) {
		$this->referenceRewriters[] = $rewriter;
		return $this;
	}

	/**
	 * @return ReferenceRewriter[]
	 */
	public function referenceRewriters() {
		return $this->referenceRewriters;
	}

	/* ---- Content rewriter ------------------------------------------------ */

	public function registerContentRewriter( ContentRewriter $rewriter ) {
		$this->contentRewriter = $rewriter;
		return $this;
	}

	/**
	 * @return ContentRewriter|null
	 */
	public function contentRewriter() {
		return $this->contentRewriter;
	}
}
