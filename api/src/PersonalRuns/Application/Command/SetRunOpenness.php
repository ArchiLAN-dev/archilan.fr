<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Command;

use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * The owner opens their draft run to friends, with an optional number of seats, or takes it back to invitations
 * only (story 43.14). Story 43.17 adds the listing for every member, which needs a short message and may carry a
 * planned date; a suspended member cannot list.
 */
final readonly class SetRunOpenness
{
    public const string OPEN_FRIENDS = Run::OPEN_FRIENDS;

    public const string OPEN_MEMBERS = Run::OPEN_MEMBERS;

    public const int MAX_SEATS_WANTED = Run::MAX_SEATS_WANTED;

    public const int MAX_PITCH_LENGTH = Run::MAX_PITCH_LENGTH;

    public function __construct(
        private RunRepositoryInterface $runs,
        private UserRepositoryInterface $users,
        private ClockInterface $clock,
    ) {
    }

    public function set(
        string $runId,
        string $callerId,
        string $openness,
        ?int $seatsWanted,
        ?string $pitch = null,
        ?\DateTimeImmutable $plannedFor = null,
    ): SetRunOpennessOutcome {
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

        $now = $this->clock->now();
        try {
            if (Run::OPEN_MEMBERS === $openness) {
                $owner = $this->users->findById($callerId);
                if (!$owner instanceof User || $owner->isAccessBlocked($now)) {
                    return SetRunOpennessOutcome::Sanctioned;
                }
                $run->listForMembers($pitch ?? '', $seatsWanted, $plannedFor, $now);
            } else {
                $run->openTo($openness, $seatsWanted, $now);
            }
        } catch (\InvalidArgumentException) {
            return SetRunOpennessOutcome::Invalid;
        }
        $this->runs->flush();

        return SetRunOpennessOutcome::Updated;
    }
}
