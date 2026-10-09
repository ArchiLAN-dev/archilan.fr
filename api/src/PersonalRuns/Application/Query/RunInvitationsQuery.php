<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Query;

use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunInvitation;
use App\PersonalRuns\Domain\Repository\RunInvitationRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;

/**
 * Invitations by name (story 43.1), as the owner follows them on the run page and as the invitee finds them on
 * « Mes parties ». A member is shown with their community card; one without a listable card is left out.
 */
final readonly class RunInvitationsQuery
{
    public function __construct(
        private RunInvitationRepositoryInterface $invitations,
        private RunRepositoryInterface $runs,
        private CommunityUserDirectoryQueryInterface $directory,
    ) {
    }

    /**
     * The run's invitations for its owner; null when the run is unknown or the caller does not own it.
     *
     * @return list<array<string, mixed>>|null
     */
    public function forRun(string $runId, string $callerId): ?array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run || !$run->isOwnedBy($callerId)) {
            return null;
        }

        $invitations = $this->invitations->findByRunId($runId);
        $cards = $this->directory->cards(array_map(static fn (RunInvitation $i): string => $i->getInviteeId(), $invitations));

        $rows = [];
        foreach ($invitations as $invitation) {
            $card = $cards[$invitation->getInviteeId()] ?? null;
            if (null === $card) {
                continue;
            }
            $rows[] = [
                'invitationId' => $invitation->getId(),
                'status' => $invitation->getStatus(),
                'invitedAt' => $invitation->getInvitedAt()->format(\DATE_ATOM),
                'respondedAt' => $invitation->getRespondedAt()?->format(\DATE_ATOM),
                'invitee' => $card,
            ];
        }

        return $rows;
    }

    /**
     * The member's pending invitations into runs still taking players, the latest first.
     *
     * @return list<array<string, mixed>>
     */
    public function forInvitee(string $userId): array
    {
        $invitations = $this->invitations->findPendingForInvitee($userId);
        $cards = $this->directory->cards(array_map(static fn (RunInvitation $i): string => $i->getInviterId(), $invitations));

        $rows = [];
        foreach ($invitations as $invitation) {
            $run = $this->runs->findById($invitation->getRunId());
            if (!$run instanceof Run || $run->isTerminal()) {
                continue;
            }
            $rows[] = [
                'invitationId' => $invitation->getId(),
                'runId' => $run->getId(),
                'runTitle' => $run->getTitle(),
                'runStatus' => $run->getStatus(),
                'invitedAt' => $invitation->getInvitedAt()->format(\DATE_ATOM),
                'inviter' => $cards[$invitation->getInviterId()] ?? null,
            ];
        }

        return $rows;
    }
}
