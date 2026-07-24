<?php

declare(strict_types=1);

namespace IdempotentImport\Tests\Support;

use IdempotentImport\ArrayLedger;
use IdempotentImport\Bootstrap;
use IdempotentImport\Config;
use IdempotentImport\Context;
use IdempotentImport\Decoder;
use IdempotentImport\IdMap;
use IdempotentImport\Importer\Comments as CommentsImporter;
use IdempotentImport\Importer\Options as OptionsImporter;
use IdempotentImport\Importer\Posts as PostsImporter;
use IdempotentImport\Importer\Terms as TermsImporter;
use IdempotentImport\Importer\Users as UsersImporter;
use IdempotentImport\Logger;
use IdempotentImport\Registry;
use IdempotentImport\Report;
use IdempotentImport\Snapshot;

/**
 * Runs the two-phase import against a FakeWordPress, mirroring Run without the
 * WP-CLI / $wpdb dependencies. Returns the Context so tests can inspect the
 * gateway, id map and report.
 */
class Harness
{
    public static function run(
        string $dir,
        FakeWordPress $wp,
        ?Config $config = null,
        string $onConflict = 'update',
        ?ArrayLedger $ledger = null,
        ?Registry $registry = null
    ): Context {
        $snapshot = new Snapshot($dir);
        $manifest = $snapshot->manifest();
        $config ??= new Config();
        $ledger ??= new ArrayLedger();
        $registry ??= Bootstrap::defaultRegistry();

        $idMap  = new IdMap($ledger);
        $logger = new Logger(null);
        $report = new Report($manifest->sourceKey(), $manifest->source(), []);

        $ctx = new Context($wp, $idMap, $config, $logger, new Decoder(), $manifest, $report);
        $ctx->onConflict = $onConflict;

        $importers = [
            new UsersImporter($snapshot, $ctx, $registry),
            new TermsImporter($snapshot, $ctx, $registry),
            new PostsImporter($snapshot, $ctx, $registry),
            new CommentsImporter($snapshot, $ctx, $registry),
            new OptionsImporter($snapshot, $ctx, $registry),
        ];

        $ctx->phase = 'create';
        foreach ($importers as $i) {
            $i->createPhase();
        }
        $ctx->phase = 'rewrite';
        foreach ($importers as $i) {
            $i->rewritePhase();
        }

        return $ctx;
    }

    /**
     * Build a bare Context for unit-testing rewriters / mappers in isolation.
     */
    public static function context(
        ?FakeWordPress $wp = null,
        ?Config $config = null,
        ?ArrayLedger $ledger = null
    ): Context {
        $wp ??= new FakeWordPress();
        $config ??= new Config();
        $ledger ??= new ArrayLedger();
        $manifest = new \IdempotentImport\Manifest(['schema_version' => '1.0.0', 'source' => []]);
        $report   = new Report('test', [], []);

        return new Context($wp, new IdMap($ledger), $config, new Logger(null), new Decoder(), $manifest, $report);
    }
}
