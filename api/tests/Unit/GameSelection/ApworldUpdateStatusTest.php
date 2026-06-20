<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Domain\ApworldUpdateStatus;
use App\GameSelection\Domain\Game;
use PHPUnit\Framework\TestCase;

final class ApworldUpdateStatusTest extends TestCase
{
    public function testNotTrackedWhenNoSourceUrl(): void
    {
        self::assertSame(Game::UPDATE_STATUS_NOT_TRACKED, ApworldUpdateStatus::compute(null, null, null, null));
        self::assertSame(Game::UPDATE_STATUS_NOT_TRACKED, ApworldUpdateStatus::compute('', null, null, null));
    }

    public function testNotTrackedWhenSourceUrlIsNotGithub(): void
    {
        self::assertSame(
            Game::UPDATE_STATUS_NOT_TRACKED,
            ApworldUpdateStatus::compute('https://gitlab.com/owner/repo', new \DateTimeImmutable(), '1.0.0', '1.0.0'),
        );
    }

    public function testUnknownWhenNeverChecked(): void
    {
        self::assertSame(
            Game::UPDATE_STATUS_UNKNOWN,
            ApworldUpdateStatus::compute('https://github.com/owner/repo', null, null, '1.0.0'),
        );
    }

    public function testUnknownWhenDeployedVersionMissing(): void
    {
        self::assertSame(
            Game::UPDATE_STATUS_UNKNOWN,
            ApworldUpdateStatus::compute('https://github.com/owner/repo', new \DateTimeImmutable(), '1.0.0', null),
        );
    }

    public function testUpToDateIgnoresVersionPrefix(): void
    {
        self::assertSame(
            Game::UPDATE_STATUS_UP_TO_DATE,
            ApworldUpdateStatus::compute('https://github.com/owner/repo', new \DateTimeImmutable(), 'v1.2.0', '1.2.0'),
        );
    }

    public function testUpdateAvailableWhenVersionsDiffer(): void
    {
        self::assertSame(
            Game::UPDATE_STATUS_UPDATE_AVAILABLE,
            ApworldUpdateStatus::compute('https://github.com/owner/repo', new \DateTimeImmutable(), '1.3.0', '1.2.0'),
        );
    }
}
