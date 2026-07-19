<?php

declare(strict_types=1);

namespace App\Tests\Unit\WeeklyRuns;

use App\SessionConfig\Application\Service\SessionConfigResolver;
use App\SessionConfig\Domain\Enum\SessionType;
use App\SessionConfig\Domain\Repository\SessionConfigOverrideRepositoryInterface;
use App\SessionConfig\Domain\Repository\SessionConfigProfileRepositoryInterface;
use App\SessionConfig\Domain\ValueObject\SessionConfig;

/**
 * Builds a real SessionConfigResolver backed by stub repos returning the domain defaults
 * (no stored profile, no override). SessionConfigResolver is final, so it cannot be mocked;
 * a real instance over interface stubs is the clean substitute.
 */
trait SessionConfigDefaultsTrait
{
    private function defaultsResolver(): SessionConfigResolver
    {
        $profiles = self::createStub(SessionConfigProfileRepositoryInterface::class);
        $profiles->method('get')->willReturnCallback(
            static fn (SessionType $type): SessionConfig => SessionConfig::defaultsFor($type),
        );
        $overrides = self::createStub(SessionConfigOverrideRepositoryInterface::class);

        return new SessionConfigResolver($profiles, $overrides);
    }
}
