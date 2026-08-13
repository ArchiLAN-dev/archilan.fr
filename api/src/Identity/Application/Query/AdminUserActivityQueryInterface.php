<?php

declare(strict_types=1);

namespace App\Identity\Application\Query;

interface AdminUserActivityQueryInterface
{
    /**
     * Raw audit rows concerning one user, newest first, capped at $limit (story 36.5).
     *
     * Counterpart ids are returned as-is: naming them is the application query's job, since it can
     * batch the lookup across the whole page.
     *
     * @return list<array{
     *     type: string,
     *     occurredAt: string,
     *     counterpartId: string|null,
     *     previousRole: string|null,
     *     newRole: string|null,
     *     subject: string|null,
     *     subjectId: string|null,
     *     granted: bool|null
     * }>
     */
    public function forUser(string $userId, int $limit): array;
}
