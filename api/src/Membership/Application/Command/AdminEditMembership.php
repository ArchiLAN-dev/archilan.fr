<?php

declare(strict_types=1);

namespace App\Membership\Application\Command;

use App\Membership\Application\Query\AdminMembershipListQuery;
use App\Membership\Application\Query\MembershipView;
use App\Membership\Domain\Entity\Membership;
use App\Membership\Domain\Repository\MembershipRepositoryInterface;
use Psr\Clock\ClockInterface;

final readonly class AdminEditMembership
{
    public function __construct(
        private MembershipRepositoryInterface $memberships,
        private AdminMembershipListQuery $membershipQuery,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return MembershipView|null null when membership not found
     */
    public function edit(
        string $membershipId,
        \DateTimeImmutable $startedAt,
        ?\DateTimeImmutable $expiresAt,
        ?string $adminNote,
    ): ?MembershipView {
        $membership = $this->memberships->findById($membershipId);
        if (!$membership instanceof Membership) {
            return null;
        }

        $resolvedExpiresAt = $expiresAt ?? $startedAt->add(new \DateInterval('P12M'));
        $now = $this->clock->now();

        $membership->adminEdit($startedAt, $resolvedExpiresAt, $adminNote, $now);
        $this->memberships->flush();

        return $this->membershipQuery->findById($membershipId);
    }
}
