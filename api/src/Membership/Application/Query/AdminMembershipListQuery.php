<?php

declare(strict_types=1);

namespace App\Membership\Application\Query;

final readonly class AdminMembershipListQuery
{
    public function __construct(private AdminMembershipListQueryInterface $query)
    {
    }

    /**
     * @return array{data: list<MembershipView>, meta: array{page: int, limit: int, total: int}}
     */
    public function search(int $page, int $limit, ?string $status, ?string $search, ?string $userId = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        return $this->query->search($page, $limit, $status, $search, $userId, $dateFrom, $dateTo);
    }

    public function findById(string $membershipId): ?MembershipView
    {
        return $this->query->findById($membershipId);
    }

    public function findLatestByUserId(string $userId): ?MembershipView
    {
        return $this->query->findLatestByUserId($userId);
    }
}
