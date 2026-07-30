<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\Community\Application\Query\CommunityLevelQuery;
use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Domain\Repository\AchievementGrantRepositoryInterface;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Identity\Application\Query\PlayerStatsQueryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\PersonalRuns\Application\Query\RecentlyPlayedGamesQueryInterface;
use App\PersonalRuns\Application\Service\PersonalRunGameSelection;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Story 9.38 AC4: the PersonalRuns game-selection write path rejects NEWLY added games whose
 * apworld failed its upload preflight (non-overridden), fails open when the runner is
 * unreachable, and never re-blocks games already present in the participant's slots.
 */
final class PersonalRunGameSelectionPreflightTest extends TestCase
{
    private const string FAILED_HASH = 'failedhash';

    public function testSaveMyGamesRejectsNewGameWithBlockingPreflight(): void
    {
        $game = $this->game('Broken World', 'broken-world', self::FAILED_HASH);
        $service = $this->service($game, $this->blockingVerdicts());

        $result = $service->saveMyGames('run-1', 'owner-1', ['gameIds' => [$game->getId()]]);

        self::assertArrayHasKey('gameIds.0', $result['errors']);
        self::assertStringContainsString('échoué au test de génération', $result['errors']['gameIds.0'][0]);
    }

    public function testSaveMyGamesAllowsGameWhenVerdictOverridden(): void
    {
        $game = $this->game('Fixed World', 'fixed-world', self::FAILED_HASH);
        $verdicts = [self::FAILED_HASH => [
            'status' => 'failed', 'error' => 'Exception: boom', 'checkedAt' => '', 'overridden' => true, 'blocks' => false,
        ]];
        $service = $this->service($game, $verdicts);

        $result = $service->saveMyGames('run-1', 'owner-1', ['gameIds' => [$game->getId()]]);

        self::assertSame([], $result['errors']);
    }

    public function testSaveMyGamesFailsOpenWhenRunnerUnreachable(): void
    {
        $game = $this->game('Some World', 'some-world', self::FAILED_HASH);
        $service = $this->service($game, []);

        $result = $service->saveMyGames('run-1', 'owner-1', ['gameIds' => [$game->getId()]]);

        self::assertSame([], $result['errors']);
    }

    public function testSaveMyGamesKeepsAlreadySelectedGameDespiteBlockingVerdict(): void
    {
        $game = $this->game('Broken World', 'broken-world', self::FAILED_HASH);
        $existingSlots = [['slotId' => 'slot-1', 'gameId' => $game->getId(), 'slotOrder' => 1]];
        $service = $this->service($game, $this->blockingVerdicts(), $existingSlots);

        $result = $service->saveMyGames('run-1', 'owner-1', ['gameIds' => [$game->getId()]]);

        self::assertSame([], $result['errors']);
    }

    /**
     * @return array<string, array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool}>
     */
    private function blockingVerdicts(): array
    {
        return [self::FAILED_HASH => [
            'status' => 'failed',
            'error' => 'Exception: Too many upgrade items.',
            'checkedAt' => '2026-07-30T12:00:00Z',
            'overridden' => false,
            'blocks' => true,
        ]];
    }

    private function game(string $name, string $slug, ?string $hash): Game
    {
        $now = new \DateTimeImmutable('2026-07-30T10:00:00+00:00');
        $game = Game::create($name, $slug, 'desc', null, 'alt', '', Game::AVAILABILITY_AVAILABLE, $now);
        if (null !== $hash) {
            $game->configureApworld($hash.'.apworld', $hash, $name, 'game: '.$name, $now);
        }

        return $game;
    }

    /**
     * @param array<string, array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool}> $verdicts
     * @param list<array{slotId: string, gameId: string, slotOrder: int}>                                            $existingSlots
     */
    private function service(Game $game, array $verdicts, array $existingSlots = []): PersonalRunGameSelection
    {
        $now = new \DateTimeImmutable('2026-07-30T10:00:00+00:00');

        $run = Run::create('owner-1', 'Ma run', $now);
        $runs = self::createStub(RunRepositoryInterface::class);
        $runs->method('findById')->willReturn($run);

        $participant = RunParticipant::create($run->getId(), 'owner-1', $now);
        if ([] !== $existingSlots) {
            $participant->replaceSlots($existingSlots);
        }
        $participants = self::createStub(RunParticipantRepositoryInterface::class);
        $participants->method('findByRunAndUser')->willReturn($participant);

        $games = self::createStub(GameRepositoryInterface::class);
        $games->method('findById')->willReturn($game);
        $games->method('findByIds')->willReturn([$game]);

        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('fetchApworldPreflights')->willReturn($verdicts);

        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturn($now);

        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        return new PersonalRunGameSelection(
            runs: $runs,
            participants: $participants,
            games: $games,
            recentlyPlayedGames: self::createStub(RecentlyPlayedGamesQueryInterface::class),
            users: self::createStub(UserRepositoryInterface::class),
            directory: self::createStub(CommunityUserDirectoryQueryInterface::class),
            levels: new CommunityLevelQuery(
                self::createStub(PlayerStatsQueryInterface::class),
                self::createStub(AchievementGrantRepositoryInterface::class),
            ),
            runnerGateway: $runner,
            messageBus: $bus,
            logger: new NullLogger(),
            clock: $clock,
        );
    }
}
