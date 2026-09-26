<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Port\GameUsageCounterInterface;
use App\GameSelection\Application\Service\AdminGameLibrary;
use App\GameSelection\Application\Support\InstallStepsNormalizer;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use PHPUnit\Framework\TestCase;

/**
 * Story 9.52: the dict sub-option values an admin declares when the apworld declares none.
 *
 * The story 9.51 normalization tests that lived here moved to ApworldIntrospectionNormalizerTest with
 * story 38.6: the normalizer no longer runs on upload but on promotion.
 */
final class AdminGameLibraryDictOptionValuesTest extends TestCase
{
    use BuildsAdminGameLibrary;

    // ── Story 9.52: what an admin declares when the apworld declares nothing ──

    public function testAnAdminCurationIsStoredApartAndSurfacesInTheEffectiveTable(): void
    {
        $game = $this->game();
        $game->recordOptionTypes(['game_options' => ['type' => 'dict', 'values' => ['battle_style']]]);

        $result = $this->library($game, self::createStub(RunnerGatewayInterface::class))
            ->saveDictOptionValues($game->getId(), 'game_options', [
                'battle_style' => ['values' => ['shift', 'set'], 'closed' => true],
            ]);

        self::assertSame([], $result['errors']);
        // Stored on its own, so the next re-introspection cannot erase it...
        self::assertSame(
            ['battle_style' => ['values' => ['shift', 'set'], 'closed' => true]],
            $game->getDictOptionValues()['game_options'] ?? null,
        );
        // ...and merged for everyone who reads the table.
        self::assertSame(
            ['values' => ['shift', 'set'], 'closed' => true],
            $game->getEffectiveOptionTypes()['game_options']['keys']['battle_style'] ?? null,
        );
    }

    public function testASingleValueIsRefused(): void
    {
        // A dropdown with one entry offers the player nothing to choose; it reads as a bug rather
        // than as a curation.
        $game = $this->game();

        $result = $this->library($game, self::createStub(RunnerGatewayInterface::class))
            ->saveDictOptionValues($game->getId(), 'game_options', [
                'battle_style' => ['values' => ['shift'], 'closed' => false],
            ]);

        self::assertArrayHasKey('values', $result['errors']);
        self::assertNull($game->getDictOptionValues());
    }

    public function testValuesAreTrimmedAndDeduplicated(): void
    {
        $game = $this->game();
        $game->recordOptionTypes(['game_options' => ['type' => 'dict']]);

        $this->library($game, self::createStub(RunnerGatewayInterface::class))
            ->saveDictOptionValues($game->getId(), 'game_options', [
                'battle_style' => ['values' => ['  shift ', 'set', 'shift', '   '], 'closed' => false],
            ]);

        self::assertSame(
            ['values' => ['shift', 'set'], 'closed' => false],
            $game->getDictOptionValues()['game_options']['battle_style'] ?? null,
        );
    }

    public function testAnEmptyMapHandsTheOptionBackToIntrospection(): void
    {
        $game = $this->game();
        $game->recordOptionTypes(['game_options' => ['type' => 'dict']]);
        $now = new \DateTimeImmutable('2026-08-28T10:00:00+00:00');
        $game->overrideDictOptionValues('game_options', ['k' => ['values' => ['a', 'b'], 'closed' => true]], $now);

        $result = $this->library($game, self::createStub(RunnerGatewayInterface::class))
            ->saveDictOptionValues($game->getId(), 'game_options', []);

        self::assertSame([], $result['errors']);
        self::assertNull($game->getDictOptionValues());
    }

    public function testAnUnknownGameIsReportedAsNotFound(): void
    {
        $repository = self::createStub(GameRepositoryInterface::class);
        $repository->method('findById')->willReturn(null);

        $game = $this->game();
        $result = $this->libraryWith($repository, self::createStub(RunnerGatewayInterface::class))
            ->saveDictOptionValues('missing', 'game_options', []);

        self::assertFalse($result['found']);
        self::assertNull($game->getDictOptionValues());
    }

    private function game(): Game
    {
        return Game::create(
            'Pokemon Platinum', 'pokemon-platinum', 'desc', null, 'alt', '',
            Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable('2026-08-28T10:00:00+00:00'),
        );
    }

    private function library(Game $game, RunnerGatewayInterface $runner): AdminGameLibrary
    {
        $repository = self::createStub(GameRepositoryInterface::class);
        $repository->method('findById')->willReturn($game);

        return $this->libraryWith($repository, $runner);
    }

    private function libraryWith(GameRepositoryInterface $repository, RunnerGatewayInterface $runner): AdminGameLibrary
    {
        $usage = self::createStub(GameUsageCounterInterface::class);
        $usage->method('count')->willReturn(0);

        $normalizer = new InstallStepsNormalizer();

        return $this->buildAdminGameLibrary($repository, $runner);
    }
}
