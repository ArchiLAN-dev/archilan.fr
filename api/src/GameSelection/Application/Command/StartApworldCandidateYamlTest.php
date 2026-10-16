<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Domain\Enum\ApworldCandidateStatus;
use App\GameSelection\Domain\Repository\ApworldCandidateRepositoryInterface;
use App\Sessions\Application\Exception\RequiredGenerationFileRefusedException;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use App\Sessions\Application\Service\RequiredGenerationFiles;
use App\Sessions\Application\Support\ProvidedGenerationFile;

/**
 * Story 38.14: an admin generates a pasted YAML against a game's apworld candidate, before putting it online.
 *
 * Reuses the orchestrator's YAML test generation (story 9.42), which takes any stored apworld by hash: the
 * candidate's own version is tested, never the one the game serves. Nothing is promoted, no incident is opened.
 * The files the admin joins (story 44.6, a ROM) are handed to the orchestrator for this test only.
 */
final readonly class StartApworldCandidateYamlTest
{
    /** A player YAML is a few kilobytes; this bounds what an admin can paste. */
    public const int MAX_YAML_BYTES = 100 * 1024;

    public function __construct(
        private ApworldCandidateRepositoryInterface $candidates,
        private RunnerGatewayInterface $runnerGateway,
        private RequiredGenerationFiles $requiredGenerationFiles,
    ) {
    }

    /** @param list<ProvidedGenerationFile> $files */
    public function start(string $gameId, string $yaml, array $files = []): ApworldCandidateYamlTestStart
    {
        if ('' === trim($yaml) || \strlen($yaml) > self::MAX_YAML_BYTES) {
            return new ApworldCandidateYamlTestStart(ApworldCandidateYamlTestOutcome::InvalidYaml);
        }

        $candidate = $this->candidates->findLatestForGame($gameId);
        $testable = [ApworldCandidateStatus::Testing, ApworldCandidateStatus::Awaiting, ApworldCandidateStatus::Rejected, ApworldCandidateStatus::Expired];
        if (null === $candidate || !\in_array($candidate->getStatus(), $testable, true)) {
            return new ApworldCandidateYamlTestStart(ApworldCandidateYamlTestOutcome::NoCandidate);
        }

        try {
            $fileIds = $this->requiredGenerationFiles->stage($files);
        } catch (RequiredGenerationFileRefusedException) {
            return new ApworldCandidateYamlTestStart(ApworldCandidateYamlTestOutcome::FileRefused);
        }

        $jobId = $this->runnerGateway->startSlotPreflight($yaml, $candidate->getApworldHash(), $fileIds);
        if (null === $jobId) {
            $this->requiredGenerationFiles->release($fileIds);

            return new ApworldCandidateYamlTestStart(ApworldCandidateYamlTestOutcome::RunnerUnavailable);
        }

        return new ApworldCandidateYamlTestStart(ApworldCandidateYamlTestOutcome::Started, $jobId);
    }
}
