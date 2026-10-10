<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Command;

use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * The owner opens their draft run to friends, with an optional number of seats, or takes it back to invitations
 * only (story 43.14).
 */
final readonly class SetRunOpenness
{
    public const string OPEN_FRIENDS = Run::OPEN_FRIENDS;

    public const int MAX_SEATS_WANTED = Run::MAX_SEATS_WANTED;

    public function __construct(
        private RunRepositoryInterface $runs,
        private ClockInterface $clock,
    ) {
    }

    public function set(string $runId, string $callerId, string $openness, ?int $seatsWanted): SetRunOpennessOutcome
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return SetRunOpennessOutcome::NotFound;
        }
        if (!$run->isOwnedBy($callerId)) {
            return SetRunOpennessOutcome::Forbidden;
        }
        if (Run::STATUS_DRAFT !== $run->getStatus()) {
            return SetRunOpennessOutcome::Locked;
        }

        try {
            $run->openTo($openness, $seatsWanted, $this->clock->now());
        } catch (\InvalidArgumentException) {
            return SetRunOpennessOutcome::Invalid;
        }
        $this->runs->flush();

        return SetRunOpennessOutcome::Updated;
    }
}
