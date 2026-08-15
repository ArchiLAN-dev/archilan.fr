<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Registers the once-per-process schema build (story 33.25).
 *
 * The work itself is in {@see BuildSchemaOnceSubscriber}; this class exists only because a PHPUnit
 * extension is the hook that lets the schema be built once per process without a static property
 * on the test base class (root CLAUDE.md forbids static mutable state).
 */
final class FunctionalSchemaExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new BuildSchemaOnceSubscriber());
    }
}
