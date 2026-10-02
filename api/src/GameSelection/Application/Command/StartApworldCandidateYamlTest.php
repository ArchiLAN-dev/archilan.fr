<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Domain\Enum\ApworldCandidateStatus;
use App\GameSelection\Domain\Repository\ApworldCandidateRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;

/**
 * Story 38.14: an admin generates a pasted YAML against a game's apworld candidate, before putting it online.
 *
 * Reuses the orchestrator's YAML test generation (story 9.42), which takes any stored apworld by hash: the
 * candidate's own version is tested, never the one the game serves. Nothing is promoted, no incident is opened.
 */
final readonly class StartApworldCandidateYamlTest
{
    /** A player YAML is a few kilobytes; this bounds what an admin can paste. */
    public const int MAX_YAML_BYTES = 100 * 1024;

    public function __construct(
        private ApworldCandidateRepositoryInterface $candidates,
        private RunnerGatewayInterface $runnerGateway,
    ) {
    }

    public function start(string $gameId, string $yaml): ApworldCandidateYamlTestStart
    {
        if ('' === trim($yaml) || \strlen($yaml) > self::MAX_YAML_BYTES) {
            return new ApworldCandidateYamlTestStart(ApworldCandidateYamlTestOutcome::InvalidYaml);
        }

        $candidate = $this->candidates->findLatestForGame($gameId);
        $testable = [ApworldCandidateStatus::Testing, ApworldCandidateStatus::Awaiting, ApworldCandidateStatus::Rejected, ApworldCandidateStatus::Expired];
        if (null === $candidate || !\in_array($candidate->getStatus(), $testable, true)) {
            return new ApworldCandidateYamlTestStart(ApworldCandidateYamlTestOutcome::NoCandidate);
        }

        $jobId = $this->runnerGateway->startSlotPreflight($yaml, $candidate->getApworldHash());
        if (null === $jobId) {
            return new ApworldCandidateYamlTestStart(ApworldCandidateYamlTestOutcome::RunnerUnavailable);
        }

        return new ApworldCandidateYamlTestStart(ApworldCandidateYamlTestOutcome::Started, $jobId);
    }
}
