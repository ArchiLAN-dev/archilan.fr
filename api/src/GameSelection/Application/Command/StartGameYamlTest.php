<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Application\Port\YamlTestQuotaInterface;
use App\GameSelection\Application\Support\GameYamlTestTicket;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;

/**
 * Story 9.57: a player generates the YAML shown in an option editor, solo, against the game's current apworld.
 *
 * The same orchestrator test as the slot preflight (story 9.42) and the admin candidate test (story 38.14),
 * on a YAML that does not have to be saved anywhere: an imported file can be tried before it replaces a
 * slot's config. Nothing is stored and nothing is blocked. A game that reads a ROM at generation is left to
 * the slot test, which knows how to join it, so no ROM travels outside a slot. A game marked unavailable is
 * offered to nobody, so it is not tested either.
 *
 * Every test is a real generation on the shared runner, hence the allowance per player. It is taken before
 * the runner is called, so the check and the count cannot race: a test the runner refused still counts.
 */
final readonly class StartGameYamlTest
{
    public const int MAX_YAML_BYTES = 100 * 1024;

    public function __construct(
        private GameRepositoryInterface $games,
        private RunnerGatewayInterface $runnerGateway,
        private GameYamlTestTicket $tickets,
        private YamlTestQuotaInterface $quota,
    ) {
    }

    public function start(string $userId, string $gameId, string $yaml): GameYamlTestStart
    {
        if ('' === trim($yaml) || \strlen($yaml) > self::MAX_YAML_BYTES) {
            return new GameYamlTestStart(GameYamlTestOutcome::InvalidYaml);
        }

        $game = $this->games->findById($gameId);
        if (!$game instanceof Game || !$game->isApworldReady() || Game::AVAILABILITY_UNAVAILABLE === $game->getAvailability()) {
            return new GameYamlTestStart(GameYamlTestOutcome::UnknownGame);
        }
        if ($game->needsAFileAtGeneration()) {
            return new GameYamlTestStart(GameYamlTestOutcome::RequiresFile);
        }

        if (!$this->quota->consume($userId)) {
            return new GameYamlTestStart(GameYamlTestOutcome::TooManyTests);
        }

        $jobId = $this->runnerGateway->startSlotPreflight($yaml, $game->getApworldHash());
        if (null === $jobId) {
            return new GameYamlTestStart(GameYamlTestOutcome::RunnerUnavailable);
        }

        return new GameYamlTestStart(GameYamlTestOutcome::Started, $this->tickets->issue($userId, $gameId, $jobId));
    }
}
