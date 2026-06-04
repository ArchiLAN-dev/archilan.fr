<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Application\UserDirectoryQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalUserDirectoryQuery implements UserDirectoryQueryInterface
{
    private string $table;

    public function __construct(private Connection $connection)
    {
        $this->table = $connection->quoteSingleIdentifier('user');
    }

    /**
     * @return list<array{id: string, email: string, displayName: string|null, role: string, roles: list<string>, status: string, createdAt: string, updatedAt: string, deletedAt: string|null}>
     */
    public function search(?string $query, ?string $role): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select('u.id', 'u.email', 'u.display_name', 'u.roles', 'u.created_at', 'u.updated_at', 'u.deleted_at')
            ->from($this->table, 'u')
            ->orderBy('u.created_at', 'DESC')
            ->setMaxResults(500);

        $normalizedQuery = null === $query ? '' : mb_strtolower(trim($query));
        if ('' !== $normalizedQuery) {
            $qb->andWhere($qb->expr()->or(
                'LOWER(u.email) LIKE :query',
                'LOWER(u.display_name) LIKE :query',
            ))->setParameter('query', '%'.$normalizedQuery.'%');
        }

        $rows = $qb->executeQuery()->fetchAllAssociative();

        $normalizedRole = null === $role ? '' : mb_strtolower(trim($role));

        $result = [];
        foreach ($rows as $row) {
            /** @var list<string> $roles */
            $roles = is_string($row['roles'] ?? null) ? json_decode($row['roles'], true) : [];
            if (!$this->matchesRoleFilter($roles, $normalizedRole)) {
                continue;
            }
            $result[] = $this->rowPayload($row, $roles);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $roles
     *
     * @return array{id: string, email: string, displayName: string|null, role: string, roles: list<string>, status: string, createdAt: string, updatedAt: string, deletedAt: string|null}
     */
    private function rowPayload(array $row, array $roles): array
    {
        return [
            'id' => is_string($row['id'] ?? null) ? $row['id'] : '',
            'email' => is_string($row['email'] ?? null) ? $row['email'] : '',
            'displayName' => is_string($row['display_name'] ?? null) ? $row['display_name'] : null,
            'role' => $this->primaryRole($roles),
            'roles' => $roles,
            'status' => null !== ($row['deleted_at'] ?? null) ? 'deleted' : 'active',
            'createdAt' => is_string($row['created_at'] ?? null) ? $row['created_at'] : '',
            'updatedAt' => is_string($row['updated_at'] ?? null) ? $row['updated_at'] : '',
            'deletedAt' => is_string($row['deleted_at'] ?? null) ? $row['deleted_at'] : null,
        ];
    }

    /** @param list<string> $roles */
    private function matchesRoleFilter(array $roles, string $role): bool
    {
        return match ($role) {
            '', 'all' => true,
            'admin', 'role_admin' => in_array('ROLE_ADMIN', $roles, true),
            'member', 'membre', 'role_member' => in_array('ROLE_MEMBER', $roles, true)
                && !in_array('ROLE_ADMIN', $roles, true),
            'user', 'role_user' => !in_array('ROLE_MEMBER', $roles, true)
                && !in_array('ROLE_ADMIN', $roles, true),
            default => false,
        };
    }

    /** @param list<string> $roles */
    private function primaryRole(array $roles): string
    {
        if (in_array('ROLE_ADMIN', $roles, true)) {
            return 'admin';
        }

        if (in_array('ROLE_MEMBER', $roles, true)) {
            return 'member';
        }

        return 'user';
    }
}
