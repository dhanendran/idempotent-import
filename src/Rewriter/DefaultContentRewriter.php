<?php

namespace IdempotentImport\Rewriter;

use IdempotentImport\Context;
use IdempotentImport\Contracts\ContentRewriter;

/**
 * Block- and URL-aware post_content rewriter.
 *
 * Rewrites, using the completed IdMap / URL map:
 *   - core block id attributes in block comment delimiters, e.g.
 *     <!-- wp:image {"id":123,...} --> and wp:cover / wp:media-text / etc.
 *   - the wp-image-{id} body class emitted by the image block
 *   - source attachment URLs -> destination attachment URLs
 *
 * Only ids that resolve to a destination post/attachment are touched; unknown
 * ids are left intact. Projects with custom blocks that embed ids should
 * register an additional ContentRewriter.
 */
class DefaultContentRewriter implements ContentRewriter {

	/**
	 * The URL map, longest source URL first, built once per run.
	 *
	 * Rebuilding it per post meant a full ledger query plus a sort for every post
	 * in the migration. The map is complete before the rewrite phase starts — every
	 * attachment is created in phase 1 — so one build is enough.
	 *
	 * @var array{from:string[],to:string[]}|null
	 */
	private $urlMap = null;

	/**
	 * Root-relative form of the base pairs in the URL map, built once per run.
	 *
	 * @var array<string,string>
	 */
	private $pathMap = array();

	public function rewrite( $html, array $post, Context $ctx ) {
		if ( '' === (string) $html ) {
			return $html;
		}

		$html = $this->rewriteBlockIds( $html, $ctx );
		$html = $this->rewriteImageClasses( $html, $ctx );
		$html = $this->rewriteUrls( $html, $ctx );

		if ( function_exists( 'apply_filters' ) ) {
			$html = apply_filters( 'idempotent_import_rewrite_post_content', $html, $post, $ctx->idMap );
		}
		return $html;
	}

	/**
	 * Rewrite "id":N inside Gutenberg block delimiters.
	 *
	 * @param string  $html
	 * @param Context $ctx
	 * @return string
	 */
	private function rewriteBlockIds( $html, Context $ctx ) {
		return preg_replace_callback(
			'/("id"\s*:\s*)(\d+)/',
			function ( $m ) use ( $ctx ) {
				$dest = $ctx->idMap->post( (int) $m[2] );
				return $dest ? $m[1] . $dest : $m[0];
			},
			$html
		);
	}

	/**
	 * Rewrite wp-image-{id} classes.
	 *
	 * @param string  $html
	 * @param Context $ctx
	 * @return string
	 */
	private function rewriteImageClasses( $html, Context $ctx ) {
		return preg_replace_callback(
			'/wp-image-(\d+)/',
			function ( $m ) use ( $ctx ) {
				$dest = $ctx->idMap->post( (int) $m[1] );
				return $dest ? 'wp-image-' . $dest : $m[0];
			},
			$html
		);
	}

	/**
	 * Replace known source attachment URLs with their destination URLs.
	 *
	 * @param string  $html
	 * @param Context $ctx
	 * @return string
	 */
	private function rewriteUrls( $html, Context $ctx ) {
		if ( null === $this->urlMap ) {
			$urls = $ctx->idMap->allUrls();
			// Longest source URLs first, so a base URL never shadows a more specific one.
			uksort(
				$urls,
				static function ( $a, $b ) {
					return strlen( $b ) - strlen( $a );
				}
			);
			$this->urlMap = array(
				'from' => array_keys( $urls ),
				'to'   => array_values( $urls ),
			);
			$this->pathMap = $this->buildPathMap( $this->urlMap['from'], $this->urlMap['to'] );
		}

		if ( empty( $this->urlMap['from'] ) ) {
			return $html;
		}

		$html = str_replace( $this->urlMap['from'], $this->urlMap['to'], $html );

		// Root-relative references — `url(/wp-content/uploads/sites/7/…)` in inline CSS,
		// srcset entries, hand-written hrefs — carry no host for the map above to match.
		// The lookbehind keeps the pattern off the path half of an absolute URL.
		foreach ( $this->pathMap as $srcPath => $destPath ) {
			$rewritten = preg_replace( '#(?<![\w:/])' . preg_quote( $srcPath, '#' ) . '#', $destPath, $html );
			// preg_replace returns null on failure; keeping $html leaves the pass a no-op
			// rather than blanking post_content.
			if ( null !== $rewritten ) {
				$html = $rewritten;
			}
		}

		return $html;
	}

	/**
	 * Reduce the URL map to the base pairs, as paths.
	 *
	 * A pair is a base when another source URL sits underneath it — true of the uploads
	 * base, false of a per-attachment URL. Only a base is safe to apply as a prefix, and
	 * only a base is present when the paths map cleanly (a sideload derives its own path,
	 * so no base pair is recorded and this stays empty).
	 *
	 * @param string[] $from
	 * @param string[] $to
	 * @return array<string,string>
	 */
	protected function buildPathMap( array $from, array $to ) {
		$pairs = array();

		foreach ( $from as $i => $src ) {
			$isBase = false;
			foreach ( $from as $j => $other ) {
				if ( $i !== $j && 0 === strpos( $other, $src . '/' ) ) {
					$isBase = true;
					break;
				}
			}
			if ( ! $isBase ) {
				continue;
			}

			$srcPath  = (string) parse_url( $src, PHP_URL_PATH );
			$destPath = (string) parse_url( $to[ $i ], PHP_URL_PATH );
			if ( '' === $srcPath || '' === $destPath || $srcPath === $destPath ) {
				continue;
			}

			$pairs[ $srcPath ] = $destPath;
		}

		return $pairs;
	}
}
