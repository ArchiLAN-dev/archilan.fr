<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Domain\Game;
use PHPUnit\Framework\TestCase;

final class ArchipelagoGameUpdateStatusTest extends TestCase
{
    private function makeGame(): Game
    {
        return Game::create(
            'Test Game',
            'test-game',
            'A description.',
            null,
            'Test Game cover',
            '',
            Game::AVAILABILITY_AVAILABLE,
            new \DateTimeImmutable(),
        );
    }

    public function testNotTrackedWhenSourceUrlNull(): void
    {
        $game = $this->makeGame();
        self::assertSame(Game::UPDATE_STATUS_NOT_TRACKED, $game->computeApworldUpdateStatus());
    }

    public function testUnknownWhenSourceUrlSetButNeverChecked(): void
    {
        $game = $this->makeGame();
        $game->updateCatalogueMetadata(sourceUrl: 'https://github.com/owner/repo');
        self::assertSame(Game::UPDATE_STATUS_UNKNOWN, $game->computeApworldUpdateStatus());
    }

    public function testUpToDateWhenVersionsMatchWithoutVPrefix(): void
    {
        $game = $this->makeGame();
        $game->updateCatalogueMetadata(sourceUrl: 'https://github.com/owner/repo', deployedVersion: '1.2.0');
        $game->recordApworldCheck('1.2.0', new \DateTimeImmutable());
        self::assertSame(Game::UPDATE_STATUS_UP_TO_DATE, $game->computeApworldUpdateStatus());
    }

    public function testUpToDateWhenVersionsMatchWithVPrefix(): void
    {
        $game = $this->makeGame();
        $game->updateCatalogueMetadata(sourceUrl: 'https://github.com/owner/repo', deployedVersion: '1.2.0');
        $game->recordApworldCheck('v1.2.0', new \DateTimeImmutable());
        self::assertSame(Game::UPDATE_STATUS_UP_TO_DATE, $game->computeApworldUpdateStatus());
    }

    public function testUpdateAvailableWhenVersionsDiffer(): void
    {
        $game = $this->makeGame();
        $game->updateCatalogueMetadata(sourceUrl: 'https://github.com/owner/repo', deployedVersion: '1.1.0');
        $game->recordApworldCheck('v1.2.0', new \DateTimeImmutable());
        self::assertSame(Game::UPDATE_STATUS_UPDATE_AVAILABLE, $game->computeApworldUpdateStatus());
    }

    public function testUnknownWhenDeployedVersionNullAfterCheck(): void
    {
        $game = $this->makeGame();
        $game->updateCatalogueMetadata(sourceUrl: 'https://github.com/owner/repo');
        $game->recordApworldCheck('1.2.0', new \DateTimeImmutable());
        self::assertSame(Game::UPDATE_STATUS_UNKNOWN, $game->computeApworldUpdateStatus());
    }

    public function testNotTrackedWhenSourceUrlIsNotGitHub(): void
    {
        $game = $this->makeGame();
        $game->updateCatalogueMetadata(sourceUrl: 'https://example.com/owner/repo');
        self::assertSame(Game::UPDATE_STATUS_NOT_TRACKED, $game->computeApworldUpdateStatus());
    }

    public function testNewFieldsDefaultToFalseAndNull(): void
    {
        $game = $this->makeGame();
        self::assertFalse($game->isAdultContent());
        self::assertFalse($game->isBundledWithAp());
        self::assertFalse($game->isAvailabilityLocked());
        self::assertNull($game->getCatalogSheetName());
        self::assertNull($game->getApworldSourceUrl());
        self::assertNull($game->getApworldDeployedVersion());
        self::assertNull($game->getApworldLatestVersion());
        self::assertNull($game->getApworldCheckedAt());
        self::assertNull($game->getIgdbId());
    }
}
