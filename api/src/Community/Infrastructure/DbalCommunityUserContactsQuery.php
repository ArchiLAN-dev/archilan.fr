<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Application\CommunityUserContactsQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalCommunityUserContactsQuery implements CommunityUserContactsQueryInterface
{
    private string $userTable;

    public function __construct(private Connection $connection)
    {
        $this->userTable = $connection->quoteSingleIdentifier('user');
    }

    public function forUser(string $userId): ?array
    {
        $qb = $this->connection->createQueryBuilder();
        $row = $qb
            ->select('u.discord_id', 'u.steam_profile')
            ->from($this->userTable, 'u')
            ->where($qb->expr()->eq('u.id', ':id'))
            ->setParameter('id', $userId)
            ->executeQuery()
            ->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return [
            'discordId' => is_string($row['discord_id'] ?? null) ? $row['discord_id'] : null,
            'steamProfile' => is_string($row['steam_profile'] ?? null) ? $row['steam_profile'] : null,
        ];
    }
}
