<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Application\Support\GameYamlTestTicket;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

/**
 * Story 9.57: a player generates the YAML shown in an option editor, solo, against the game's current apworld.
 *
 * The same orchestrator test as the slot preflight (story 9.42) and the admin candidate test (story 38.14),
 * on a YAML that does not have to be saved anywhere: an imported file can be tried before it replaces a
 * slot's config. Nothing is stored and nothing is blocked. A game that reads a ROM at generation is left to
 * the slot test, which knows how to join it, so no ROM travels outside a slot.
 *
 * Every test is a real generation on the shared runner, hence the cap per player: a fixed window kept in
 * the application cache, which is enough for a guard against a stuck button or a script.
 */
final readonly class StartGameYamlTest
{
    public const int MAX_YAML_BYTES = 100 * 1024;
    public const int MAX_TESTS_PER_WINDOW = 5;
    public const int WINDOW_SECONDS = 600;

    public function __construct(
        private GameRepositoryInterface $games,
        private RunnerGatewayInterface $runnerGateway,
        private GameYamlTestTicket $tickets,
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
    ) {
    }

    public function start(string $userId, string $gameId, string $yaml): GameYamlTestStart
    {
        if ('' === trim($yaml) || \strlen($yaml) > self::MAX_YAML_BYTES) {
            return new GameYamlTestStart(GameYamlTestOutcome::InvalidYaml);
        }

        $game = $this->games->findById($gameId);
        if (!$game instanceof Game || !$game->isApworldReady()) {
            return new GameYamlTestStart(GameYamlTestOutcome::UnknownGame);
        }
        if ($game->needsAFileAtGeneration()) {
            return new GameYamlTestStart(GameYamlTestOutcome::RequiresFile);
        }

        $window = intdiv($this->clock->now()->getTimestamp(), self::WINDOW_SECONDS);
        $counter = $this->cache->getItem('game_yaml_test.'.hash('sha256', $userId).'.'.$window);
        $started = $counter->isHit() && \is_int($counter->get()) ? $counter->get() : 0;
        if ($started >= self::MAX_TESTS_PER_WINDOW) {
            return new GameYamlTestStart(GameYamlTestOutcome::TooManyTests);
        }

        $jobId = $this->runnerGateway->startSlotPreflight($yaml, $game->getApworldHash());
        if (null === $jobId) {
            return new GameYamlTestStart(GameYamlTestOutcome::RunnerUnavailable);
        }

        $this->cache->save($counter->set($started + 1)->expiresAfter(self::WINDOW_SECONDS));

        return new GameYamlTestStart(GameYamlTestOutcome::Started, $this->tickets->issue($userId, $gameId, $jobId));
    }
}
