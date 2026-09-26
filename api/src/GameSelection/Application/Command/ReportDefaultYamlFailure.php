<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Enum\ApworldIncidentType;
use App\GameSelection\Domain\Repository\ApworldIncidentRepositoryInterface;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\GameSelection\Domain\Service\DefaultYamlEquivalence;
use App\Shared\Application\Support\YamlDocumentReader;
use Psr\Log\LoggerInterface;

/**
 * A real generation failed for one slot (story 38.4): a player's solo config test, or a run generation
 * the failure was attributed to. The single place that decides whether the apworld is at fault.
 *
 * The import test draws one seed, so an apworld that fails one seed in ten can pass it. A failure only
 * accuses the apworld when the slot had the game's default YAML, on the apworld the game still serves:
 * a player who changed their YAML has a config problem, and an old apworld has already been replaced.
 * Accused, it opens or recurs a `default_yaml_failure` incident through {@see RecordApworldIncident},
 * so ten players failing the same evening make one incident with ten occurrences.
 */
final readonly class ReportDefaultYamlFailure
{
    public function __construct(
        private GameRepositoryInterface $games,
        private RecordApworldIncident $recordIncident,
        private ApworldIncidentRepositoryInterface $incidents,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string|null $apworldHash the slot's apworld; null when none was recorded, which is the one the game serves
     * @param string      $playerYaml  the slot's YAML; empty when never configured, which is the game's default
     */
    public function report(string $gameId, ?string $apworldHash, string $playerYaml, string $error): DefaultYamlFailureReport
    {
        $game = $this->games->findById($gameId);
        if (!$game instanceof Game) {
            return new DefaultYamlFailureReport(DefaultYamlFailureVerdict::UnknownGame);
        }

        $servedHash = $game->getApworldHash();
        $slotHash = null === $apworldHash || '' === $apworldHash ? $servedHash : $apworldHash;
        if (null === $servedHash || '' === $servedHash || $slotHash !== $servedHash) {
            return new DefaultYamlFailureReport(DefaultYamlFailureVerdict::NotServedHash);
        }

        if ('' !== trim($playerYaml)) {
            $player = YamlDocumentReader::read($playerYaml);
            if (null === $player) {
                $this->logger->warning('apworld_incident.default_yaml_failure.unreadable_yaml', ['gameId' => $gameId]);

                return new DefaultYamlFailureReport(DefaultYamlFailureVerdict::UnreadableYaml);
            }
            if (!DefaultYamlEquivalence::isEquivalent($player, YamlDocumentReader::read($game->getDefaultYaml() ?? '') ?? [])) {
                return new DefaultYamlFailureReport(DefaultYamlFailureVerdict::CustomYaml);
            }
        }

        $recording = $this->recordIncident->record($gameId, $servedHash, ApworldIncidentType::DefaultYamlFailure, $error);
        $this->incidents->flush();

        return new DefaultYamlFailureReport(DefaultYamlFailureVerdict::ApworldAccused, $recording);
    }
}
