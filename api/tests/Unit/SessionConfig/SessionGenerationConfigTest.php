<?php

declare(strict_types=1);

namespace App\Tests\Unit\SessionConfig;

use App\SessionConfig\Domain\PlandoOption;
use App\SessionConfig\Domain\SessionGenerationConfig;
use App\SessionConfig\Domain\SpoilerLevel;
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
