<?php

declare(strict_types=1);

namespace App\Membership\Application;

use App\Membership\Domain\Membership;
use App\Membership\Domain\MembershipRepositoryInterface;

final readonly class AdminEditMembership
{
    public function __construct(
        private MembershipRepositoryInterface $memberships,
        private AdminMembershipListQuery $membershipQuery,
    ) {
    }

    /**
     * @return array<string, mixed>|null null when membership not found
     */
    public function edit(
        string $membershipId,
        \DateTimeImmutable $startedAt,
        ?\DateTimeImmutable $expiresAt,
        ?string $adminNote,
    ): ?array {
        $membership = $this->memberships->findById($membershipId);
        if (!$membership instanceof Membership) {
            return null;
        }

        $resolvedExpiresAt = $expiresAt ?? $startedAt->add(new \DateInterval('P12M'));
        $now = new \DateTimeImmutable();

        $membership->adminEdit($startedAt, $resolvedExpiresAt, $adminNote, $now);
        $this->memberships->flush();

        return $this->membershipQuery->findById($membershipId);
    }
}
