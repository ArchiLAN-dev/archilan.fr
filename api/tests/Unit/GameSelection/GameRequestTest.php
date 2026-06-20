<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Domain\GameRequest;
use PHPUnit\Framework\TestCase;

final class GameRequestTest extends TestCase
{
    public function testCreateTrimsNameAndStoresNormalizedForm(): void
    {
        $now = new \DateTimeImmutable('2026-06-17 10:00:00');

        $request = GameRequest::create('  Super Metroid  ', 'user-1', $now);

        self::assertSame('Super Metroid', $request->getGameName());
        self::assertSame('super metroid', $request->getNormalizedName());
        self::assertSame('user-1', $request->getUserId());
        self::assertSame($now, $request->getCreatedAt());
        self::assertNotSame('', $request->getId());
    }

    public function testNormalizeLowercasesAndTrims(): void
    {
        self::assertSame('the legend of zelda', GameRequest::normalize('  The Legend Of Zelda '));
        self::assertSame('factorio', GameRequest::normalize('FACTORIO'));
    }

    public function testTwoRequestsGetDistinctIds(): void
    {
        $now = new \DateTimeImmutable();

        self::assertNotSame(
            GameRequest::create('A', 'user-1', $now)->getId(),
            GameRequest::create('A', 'user-1', $now)->getId(),
        );
    }
}
