<?php

declare(strict_types=1);

namespace App\Membership\Application\Query;

interface AdminMembershipListQueryInterface
{
    /**
     * @return array{data: list<MembershipView>, meta: array{page: int, limit: int, total: int}}
     */
    public function search(int $page, int $limit, ?string $status, ?string $search, ?string $userId = null, ?string $dateFrom = null, ?string $dateTo = null): array;

    public function findById(string $membershipId): ?MembershipView;

    public function findLatestByUserId(string $userId): ?MembershipView;
}
