<?php

declare(strict_types=1);

namespace App\Tests\Unit\SessionConfig;

use App\SessionConfig\Domain\Enum\PlandoOption;
use App\SessionConfig\Domain\Enum\SpoilerLevel;
use App\SessionConfig\Domain\ValueObject\SessionGenerationConfig;
use PHPUnit\Framework\TestCase;

final class SessionGenerationConfigTest extends TestCase
{
    public function testConstructDedupesPlandoPreservingOrder(): void
    {
        $c = new SessionGenerationConfig(
            [PlandoOption::Bosses, PlandoOption::Items, PlandoOption::Bosses],
            false,
            SpoilerLevel::WithPaths,
        );
        self::assertSame([PlandoOption::Bosses, PlandoOption::Items], $c->plandoOptions);
    }

    public function testToGenerationParamsShape(): void
    {
        $params = new SessionGenerationConfig(
            [PlandoOption::Items, PlandoOption::Texts],
            true,
            SpoilerLevel::None,
        )->toGenerationParams();

        self::assertSame(['items', 'texts'], $params['plandoOptions']);
        self::assertTrue($params['race']);
        self::assertSame(0, $params['spoiler']);
    }

    /** Story 56.6: the orchestrator reads an absent cap as off, and only receives k when the cap is on. */
    public function testTheProgressionCapIsSentOnlyWhenOn(): void
    {
        $off = new SessionGenerationConfig([], false, SpoilerLevel::WithPaths);
        self::assertFalse($off->progressionCap);
        self::assertArrayNotHasKey('progressionCap', $off->toGenerationParams());
        self::assertFalse($off->toArray()['progressionCap']);

        $on = new SessionGenerationConfig([], false, SpoilerLevel::WithPaths, progressionCap: true);
        self::assertSame(SessionGenerationConfig::PROGRESSION_CAP_FACTOR, $on->toGenerationParams()['progressionCap'] ?? null);
        self::assertTrue($on->toArray()['progressionCap']);
    }

    public function testSpoilerLevelRejectsOutOfRange(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('invalid_spoiler');
        SpoilerLevel::fromInt(4);
    }

    public function testPlandoOptionRejectsUnknown(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('invalid_plando_option');
        PlandoOption::fromString('weapons');
    }
}
