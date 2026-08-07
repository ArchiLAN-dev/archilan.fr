<?php

declare(strict_types=1);

namespace App\Identity\Application\Query;

use App\Identity\Domain\Repository\UserRepositoryInterface;

/**
 * The account's audit timeline for the admin sheet (story 36.5).
 *
 * Its one real job beyond delegating: naming the counterparts. The raw rows carry ids, and an id tells a
 * reviewer nothing - so every user referenced by the page is resolved in a single batch, never one
 * lookup per line.
 */
final readonly class AdminUserActivityQuery
{
    private const int DEFAULT_LIMIT = 50;
    private const int MAX_LIMIT = 200;

    public function __construct(
        private AdminUserActivityQueryInterface $activity,
        private UserRepositoryInterface $users,
    ) {
    }

    /**
     * Null when no such account exists, so the controller can answer 404 with a single Application call
     * (AC-P4) instead of pairing this read with an existence check of its own.
     *
     * @return list<AdminUserActivityEntry>|null
     */
    public function forUser(string $userId, int $limit): ?array
    {
        if (null === $this->users->findById($userId)) {
            return null;
        }

        $rows = $this->activity->forUser($userId, $this->clampLimit($limit));
        if ([] === $rows) {
            return [];
        }

        $names = $this->namesFor($rows);

        $entries = [];
        foreach ($rows as $row) {
            $counterpartId = $row['counterpartId'];
            $entries[] = new AdminUserActivityEntry(
                $row['type'],
                $row['occurredAt'],
                $counterpartId,
                // A deleted or unknown counterpart must not swallow the entry: the event happened, and
                // saying so with an unnamed party beats hiding it.
                null === $counterpartId ? null : ($names[$counterpartId] ?? null),
                $row['previousRole'],
                $row['newRole'],
                $row['subject'],
                $row['subjectId'],
                $row['granted'],
            );
        }

        return $entries;
    }

    /**
     * @param list<array{type: string, occurredAt: string, counterpartId: string|null, previousRole: string|null, newRole: string|null, subject: string|null, subjectId: string|null, granted: bool|null}> $rows
     *
     * @return array<string, string>
     */
    private function namesFor(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (null !== $row['counterpartId']) {
                $ids[] = $row['counterpartId'];
            }
        }

        $ids = array_values(array_unique($ids));
        if ([] === $ids) {
            return [];
        }

        $names = [];
        foreach ($this->users->findByIds($ids) as $user) {
            $names[$user->getId()] = $user->getDisplayName();
        }

        return $names;
    }

    private function clampLimit(int $limit): int
    {
        if ($limit <= 0) {
            return self::DEFAULT_LIMIT;
        }

        return min($limit, self::MAX_LIMIT);
    }
}
