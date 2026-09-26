<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\ValueObject\ApworldUpdateStatus;
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

    public function testUpdateAvailableWhenTheLatestIsNewer(): void
    {
        self::assertSame(
            Game::UPDATE_STATUS_UPDATE_AVAILABLE,
            ApworldUpdateStatus::compute('https://github.com/owner/repo', new \DateTimeImmutable(), '1.3.0', '1.2.0'),
        );
    }

    public function testAnOlderLatestIsNeverAnUpdate(): void
    {
        // Story 38.5: the tracker saw 0.16.0 while 0.17.0 was deployed, and called it an update.
        self::assertSame(
            Game::UPDATE_STATUS_UP_TO_DATE,
            ApworldUpdateStatus::compute('https://github.com/owner/repo', new \DateTimeImmutable(), 'CrystalProject-v0.16.0', 'CrystalProject-v0.17.0'),
        );
    }

    public function testTheSameVersionWrittenDifferentlyIsUpToDate(): void
    {
        self::assertSame(
            Game::UPDATE_STATUS_UP_TO_DATE,
            ApworldUpdateStatus::compute('https://github.com/owner/repo', new \DateTimeImmutable(), 'CrystalProject-v0.18.2', '0.18.2'),
        );
    }

    public function testVersionsAreOrderedNumerically(): void
    {
        self::assertSame(
            Game::UPDATE_STATUS_UPDATE_AVAILABLE,
            ApworldUpdateStatus::compute('https://github.com/owner/repo', new \DateTimeImmutable(), '0.10.0', '0.9.0'),
        );
    }

    public function testAnUnreadableVersionIsUndetermined(): void
    {
        self::assertSame(
            Game::UPDATE_STATUS_UNDETERMINED,
            ApworldUpdateStatus::compute('https://github.com/owner/repo', new \DateTimeImmutable(), 'latest', '1.2.0'),
        );
        self::assertSame(
            Game::UPDATE_STATUS_UNDETERMINED,
            ApworldUpdateStatus::compute('https://github.com/owner/repo', new \DateTimeImmutable(), '1.2.0', 'nightly'),
        );
    }
}
