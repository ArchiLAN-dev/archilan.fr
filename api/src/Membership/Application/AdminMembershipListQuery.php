<?php

declare(strict_types=1);

namespace App\Membership\Application;

final readonly class AdminMembershipListQuery
{
    public function __construct(private AdminMembershipListQueryInterface $query)
    {
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, limit: int, total: int}}
     */
    public function search(int $page, int $limit, ?string $status, ?string $search, ?string $userId = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        return $this->query->search($page, $limit, $status, $search, $userId, $dateFrom, $dateTo);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $membershipId): ?array
    {
        return $this->query->findById($membershipId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLatestByUserId(string $userId): ?array
    {
        return $this->query->findLatestByUserId($userId);
    }
}
