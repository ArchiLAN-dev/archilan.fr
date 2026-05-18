<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Application\Message\SyncDiscordRoleMessage;
use App\Identity\Domain\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class DiscordResyncAllUsers implements DiscordResyncAllUsersInterface
{
    private string $table;

    public function __construct(
        private Connection $connection,
        EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
        $this->table = $connection->quoteSingleIdentifier($em->getClassMetadata(User::class)->getTableName());
    }

    public function run(bool $dryRun = false): int
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('u.id', 'u.discord_id', 'u.roles')
            ->from($this->table, 'u')
            ->where('u.discord_id IS NOT NULL')
            ->executeQuery();

        $count = 0;
        $failures = 0;
        foreach ($rows->iterateAssociative() as $row) {
            $userId = is_string($row['id'] ?? null) ? $row['id'] : '';
            $discordId = is_string($row['discord_id'] ?? null) ? $row['discord_id'] : '';

            if ('' === $userId || '' === $discordId) {
                continue;
            }

            $roles = self::normalizeRoles($row['roles'] ?? null);

            if (!$dryRun) {
                try {
                    $this->bus->dispatch(new SyncDiscordRoleMessage($userId, $discordId, $roles));
                } catch (\Throwable $exception) {
                    $this->logger->error('Discord role resync dispatch failed.', [
                        'userId' => $userId,
                        'discordId' => $discordId,
                        'exception' => $exception,
                    ]);
                    ++$failures;
                    continue;
                }
            }

            ++$count;
        }

        if ($failures > 0) {
            throw new \RuntimeException(sprintf('Failed to dispatch %d Discord role sync message%s.', $failures, $failures > 1 ? 's' : ''));
        }

        return $count;
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
