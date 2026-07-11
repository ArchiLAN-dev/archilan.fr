<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;

/*
 * Conservative Rector configuration (story 33.13).
 *
 * Scope: PHP 8.4 language-level modernisation only. Style belongs to cs-fixer
 * (@Symfony preset); architectural rules belong to app:architecture:ddd. Symfony
 * sets (rector/rector-symfony) were deliberately deferred: the epic asks for a
 * conservative baseline first (decision recorded in the 33.13 story).
 *
 * Hard skips:
 *  - src/Sessions: frozen until Epic 32 merges (same carve-out as every 33.x story).
 *  - migrations/ is simply not in the paths: merged migrations are immutable.
 *
 * Advisory gate: `composer rector` runs --dry-run; CI mirrors it (continue-on-error)
 * until the baseline proves stable, then it flips to a hard gate.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/src/Sessions',
        // The DDD validator's forbidden-import lists are SCAN PATTERNS, not class references.
        // As single-quoted strings their source text carries doubled backslashes and cannot
        // self-match; rewritten to ::class references the file contains the literal FQCN
        // sequence and the validator flags itself (discovered on the first 33.13 apply).
        StringClassNameToClassConstantRector::class,
    ])
    ->withPhpSets(php84: true);
