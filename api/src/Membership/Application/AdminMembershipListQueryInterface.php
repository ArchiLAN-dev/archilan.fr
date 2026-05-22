<?php

declare(strict_types=1);

namespace App\Membership\Application;

interface AdminMembershipListQueryInterface
{
    /**
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, limit: int, total: int}}
     */
    public function search(int $page, int $limit, ?string $status, ?string $search, ?string $userId = null, ?string $dateFrom = null, ?string $dateTo = null): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $membershipId): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function findLatestByUserId(string $userId): ?array;
}
