<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Application\Support\ApworldIntrospectionNormalizer;
use App\GameSelection\Domain\Entity\ApworldCandidate;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\ApworldIncidentRepositoryInterface;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use Psr\Clock\ClockInterface;

/**
 * Switches a game to its candidate apworld (story 38.6): the one place where an apworld becomes the
 * one players get, whether the verdict passed or an admin forced it.
 *
 * Everything moves together - hash, storage keys, default YAML, option types, location names and the
 * deployed version - so the game never serves a mix of two versions. Option types and location names
 * are read from the orchestrator now rather than at upload: its introspection runs in the background
 * after an upload, and is done by the time a verdict exists.
 *
 * Joins the caller's unit of work: it never flushes.
 */
final readonly class PromoteApworldCandidate
{
    public function __construct(
        private GameRepositoryInterface $games,
        private ApworldIncidentRepositoryInterface $incidents,
        private RunnerGatewayInterface $runnerGateway,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Null when the game no longer exists: there is nothing to switch.
     */
    public function promote(ApworldCandidate $candidate, ?string $forcedBy): ?ApworldPromotion
    {
        $game = $this->games->findById($candidate->getGameId());
        if (!$game instanceof Game) {
            return null;
        }

        $now = $this->clock->now();
        $previousHash = $game->getApworldHash();
        $previousVersion = $game->getApworldDeployedVersion();
        $previousDefaultYaml = $game->getDefaultYaml();

        $game->configureApworld(
            $candidate->getStorageKey(),
            $candidate->getApworldHash(),
            $candidate->getArchipelagoGameName(),
            $candidate->getDefaultYaml(),
            $now,
        );
        $game->recordApworldMinioUpload($candidate->getMinioKey());
        // Same validation as the upload used to apply, the story 9.51 dict rule included.
        $game->recordOptionTypes(ApworldIntrospectionNormalizer::optionTypes($this->runnerGateway->fetchOptionTypes($candidate->getApworldHash())));
        $game->recordLocationNames(ApworldIntrospectionNormalizer::locationNames($this->runnerGateway->fetchLocationNames($candidate->getApworldHash())));
        if (null !== $candidate->getVersionTag()) {
            $game->getCatalogSync()?->recordApworldDeployment($candidate->getVersionTag());
        }

        $candidate->promote($now, $forcedBy);

        // The game got its update after all: the incidents saying it could not are over.
        $resolved = [];
        foreach ($this->incidents->findActiveForGame($game->getId()) as $incident) {
            if ($incident->getType()->isSettledByAPromotion()) {
                $incident->resolve($now, null);
                $resolved[] = $incident->getId();
            }
        }

        return new ApworldPromotion(
            $candidate->getId(),
            $game->getId(),
            $previousHash,
            $candidate->getApworldHash(),
            $previousVersion,
            $candidate->getVersionTag(),
            $previousDefaultYaml,
            $resolved,
        );
    }
}
