<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Sessions\Application\Exception\RequiredGenerationFileRefusedException;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use App\Sessions\Application\Service\RequiredGenerationFiles;
use App\Sessions\Application\Support\ProvidedGenerationFile;

/**
 * Story 11.11: an admin generates one slot of an event alone, as « Tester ma config » does for a private game
 * (story 9.42), to find the YAML that breaks the generation of a whole session.
 *
 * What is tested is what the session's generation reads: the YAML saved on the registration, else the game's default
 * one, against the apworld the game serves (SessionOrchestrator). Nothing is stored and no apworld incident is
 * opened: this is an admin checking a player's config, not a verdict on the apworld. A game that reads a file at
 * generation is not started without it, since it could only fail on its absence.
 */
final readonly class StartEventSlotYamlTest
{
    public function __construct(
        private RegistrationRepositoryInterface $registrations,
        private GameRepositoryInterface $games,
        private RunnerGatewayInterface $runnerGateway,
        private RequiredGenerationFiles $requiredGenerationFiles,
    ) {
    }

    /** @param list<ProvidedGenerationFile> $files */
    public function start(string $eventId, string $registrationId, string $slotId, array $files = []): EventSlotYamlTestStart
    {
        $registration = $this->registrations->findById($registrationId);
        if (!$registration instanceof Registration || $registration->getEventId() !== $eventId) {
            return new EventSlotYamlTestStart(EventSlotYamlTestOutcome::NotFound);
        }
        $slot = $registration->getSlot($slotId);
        $game = null !== $slot ? $this->games->findById($slot['gameId']) : null;
        if (null === $slot || !$game instanceof Game || !$game->isApworldReady()) {
            return new EventSlotYamlTestStart(EventSlotYamlTestOutcome::NotFound);
        }

        $savedYaml = is_string($slot['playerYaml'] ?? null) ? $slot['playerYaml'] : '';
        $yaml = '' !== $savedYaml ? $savedYaml : ($game->getDefaultYaml() ?? '');
        if ('' === trim($yaml)) {
            return new EventSlotYamlTestStart(EventSlotYamlTestOutcome::NoYaml);
        }
        if ([] === $files && $game->needsAFileAtGeneration()) {
            return new EventSlotYamlTestStart(EventSlotYamlTestOutcome::RequiresFile, requiredFiles: $game->getRequiredFiles());
        }

        try {
            $fileIds = $this->requiredGenerationFiles->stage($files);
        } catch (RequiredGenerationFileRefusedException) {
            return new EventSlotYamlTestStart(EventSlotYamlTestOutcome::FileRefused);
        }

        $jobId = $this->runnerGateway->startSlotPreflight($yaml, $game->getApworldHash(), $fileIds);
        if (null === $jobId) {
            $this->requiredGenerationFiles->release($fileIds);

            return new EventSlotYamlTestStart(EventSlotYamlTestOutcome::RunnerUnavailable);
        }

        return new EventSlotYamlTestStart(EventSlotYamlTestOutcome::Started, $jobId);
    }
}
