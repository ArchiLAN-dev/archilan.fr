<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

use App\Community\Domain\Enum\NameStyle;
use App\Membership\Application\Query\ActiveMembershipQueryInterface;

/**
 * Story 30.44, for the card surfaces that read raw rows (directory, leaderboards): the name style of each row,
 * with one grouped membership lookup for the whole list. A row holds the user's `id` and `roles` JSON, and the
 * profile's `holo_name` (null without a profile row: the default, on).
 */
final readonly class NameStyleResolver
{
    public function __construct(private ActiveMembershipQueryInterface $memberships)
    {
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, string|null> keyed by user id
     */
    public function forRows(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (is_string($row['id'] ?? null)) {
                $ids[] = $row['id'];
            }
        }
        if ([] === $ids) {
            return [];
        }
        $members = array_flip($this->memberships->activeMemberIds($ids));

        $styles = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (!is_string($id)) {
                continue;
            }
            $rawRoles = $row['roles'] ?? null;
            $roles = is_string($rawRoles) ? json_decode($rawRoles, true) : null;
            $isAdmin = is_array($roles) && in_array('ROLE_ADMIN', $roles, true);
            $styles[$id] = NameStyle::for($isAdmin, isset($members[$id]), $this->enabled($row['holo_name'] ?? null))?->value;
        }

        return $styles;
    }

    /** DBAL returns booleans as bool, int or string depending on the driver. */
    private function enabled(mixed $value): bool
    {
        return match (true) {
            null === $value => true,
            is_bool($value) => $value,
            default => in_array($value, [1, '1', 't', 'true'], true),
        };
    }
}
