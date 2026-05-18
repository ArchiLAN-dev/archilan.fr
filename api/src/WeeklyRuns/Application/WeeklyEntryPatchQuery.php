<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

use App\WeeklyRuns\Domain\WeeklyEntry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class WeeklyEntryPatchQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
    ) {
    }

    /**
     * @return array{externalSessionId: string, slotName: string}|null
     */
    public function forEntry(string $weeklyRunId, string $entryId, string $userId): ?array
    {
        $entry = $this->entityManager->find(WeeklyEntry::class, $entryId);
        if (!$entry instanceof WeeklyEntry) {
            return null;
        }
        if ($entry->getWeeklyRunId() !== $weeklyRunId || $entry->getUserId() !== $userId) {
            return null;
        }

        $externalSessionId = $entry->getExternalSessionId();
        if (null === $externalSessionId) {
            return null;
        }

        $userTable = $this->connection->quoteSingleIdentifier('user');
        $row = $this->connection->createQueryBuilder()
            ->select('u.display_name')
            ->from($userTable, 'u')
            ->where('u.id = :userId')
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchAssociative();

        $displayName = false !== $row ? ($row['display_name'] ?? null) : null;
        $slotName = is_string($displayName) ? $displayName : 'ArchiLAN';

        return ['externalSessionId' => $externalSessionId, 'slotName' => $slotName];
    }
}
