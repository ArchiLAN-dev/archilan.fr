<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;

/*
 * Conservative Rector configuration (story 33.13).
 *
 * Scope: PHP 8.4 language-level modernisation only. Style belongs to cs-fixer
 * (@Symfony preset); architectural rules belong to app:architecture:ddd. Symfony
 * rules were deliberately deferred: the epic asks for a conservative baseline first
 * (decision recorded in the 33.13 story). NOTE: Rector 2.x BUNDLES the Symfony rules
 * and conflicts with the standalone rector/rector-symfony package - the follow-up
 * path is Rector's own Symfony set API, not a new dependency.
 *
 * Hard skips:
 *  - migrations/ is simply not in the paths: merged migrations are immutable.
 *
 * The Sessions freeze (src/Sessions + tests/Unit/Sessions) is LIFTED: Epic 32 merged and
 * story 33.20 migrated the context, so Rector now analyses it like every other.
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
        // The DDD validator's forbidden-import lists are SCAN PATTERNS - string DATA, not class
        // references. This skip survives the tokenizer refactor (story 33.23), and the original
        // reason for it was wrong: it is not that a lexical scan cannot tell code from prose (it
        // can now - PhpSource blanks comments and fills string literals). It is that `::class` IS
        // code. Rewriting these constants to ::class would put the very FQCN sequences the
        // validator hunts for into its own executable source, and the import rule - which must
        // keep catching a fully-qualified usage that has no `use` statement - would flag itself.
        // A file whose job is to NAME forbidden classes has to hold them as data.
        StringClassNameToClassConstantRector::class,
    ])
    ->withPhpSets(php84: true);
