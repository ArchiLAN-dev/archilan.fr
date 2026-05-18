<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DiscordBotUsersQuery
{
    private string $table;

    public function __construct(
        private Connection $connection,
        EntityManagerInterface $em,
    ) {
        $this->table = $connection->quoteSingleIdentifier($em->getClassMetadata(User::class)->getTableName());
    }

    /**
     * @return array{data: list<array{id: string, email: string, displayName: string|null, roles: list<string>, discordId: string, discordUsername: string|null, discordRoleSyncedAt: string|null, discordSyncError: string|null}>, meta: array{page: int, limit: int, total: int}}
     */
    public function query(int $page = 1, int $limit = 50): array
    {
        $page = max(1, $page);
        $limit = min(max(1, $limit), 200);
        $offset = ($page - 1) * $limit;

        $countQb = $this->connection->createQueryBuilder();
        $totalRaw = $countQb
            ->select('COUNT(*)')
            ->from($this->table, 'u')
            ->where('u.discord_id IS NOT NULL')
            ->executeQuery()
            ->fetchOne();

        $total = is_int($totalRaw) ? $totalRaw : (is_string($totalRaw) ? (int) $totalRaw : 0);

        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select(
                'u.id',
                'u.email',
                'u.display_name',
                'u.roles',
                'u.discord_id',
                'u.discord_username',
                'u.discord_role_synced_at',
                'u.discord_sync_error',
            )
            ->from($this->table, 'u')
            ->where('u.discord_id IS NOT NULL')
            ->orderBy('u.email', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->executeQuery()
            ->fetchAllAssociative();

        $data = [];
        foreach ($rows as $row) {
            $roles = self::normalizeRoles($row['roles'] ?? null);
            $discordId = is_string($row['discord_id'] ?? null) ? $row['discord_id'] : '';
            if ('' === $discordId) {
                continue;
            }
            $data[] = [
                'id' => is_string($row['id'] ?? null) ? $row['id'] : '',
                'email' => is_string($row['email'] ?? null) ? $row['email'] : '',
                'displayName' => is_string($row['display_name'] ?? null) ? $row['display_name'] : null,
                'roles' => $roles,
                'discordId' => $discordId,
                'discordUsername' => is_string($row['discord_username'] ?? null) ? $row['discord_username'] : null,
                'discordRoleSyncedAt' => is_string($row['discord_role_synced_at'] ?? null) ? $row['discord_role_synced_at'] : null,
                'discordSyncError' => is_string($row['discord_sync_error'] ?? null) ? $row['discord_sync_error'] : null,
            ];
        }

        return [
            'data' => $data,
            'meta' => ['page' => $page, 'limit' => $limit, 'total' => $total],
        ];
    }

    /** @return list<string> */
    private static function normalizeRoles(mixed $roles): array
    {
        if (!is_string($roles)) {
            return [];
        }

        try {
            $decoded = json_decode($roles, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded) || !array_is_list($decoded)) {
            return [];
        }

        $normalized = [];
        foreach ($decoded as $role) {
            if (is_string($role)) {
                $normalized[] = $role;
            }
        }

        return $normalized;
    }
}
