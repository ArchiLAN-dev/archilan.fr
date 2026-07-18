<?php

declare(strict_types=1);

namespace App\Membership\Application\Query;

/**
 * Admin-facing read view of a membership (with joined user/profile columns), produced by
 * {@see AdminMembershipListQuery} (search/findById/findLatestByUserId) and returned by
 * {@see \App\Membership\Application\Command\AdminEditMembership::edit}. The admin controllers serialize it
 * verbatim; field order mirrors the DBAL SELECT so the JSON is byte-identical.
 */
final readonly class MembershipView
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $email,
        public ?string $displayName,
        public string $status,
        public string $startedAt,
        public string $expiresAt,
        public string $source,
        public ?string $helloassoOrderId,
        public ?string $adminNote,
    ) {
    }

    /**
     * Maps a raw DBAL row (all columns `mixed`) into the typed view, passing each value through unchanged
     * once narrowed - so the serialized JSON is byte-identical to the former associative array.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            is_string($row['id'] ?? null) ? $row['id'] : '',
            is_string($row['userId'] ?? null) ? $row['userId'] : '',
            is_string($row['email'] ?? null) ? $row['email'] : '',
            is_string($row['displayName'] ?? null) ? $row['displayName'] : null,
            is_string($row['status'] ?? null) ? $row['status'] : '',
            is_string($row['startedAt'] ?? null) ? $row['startedAt'] : '',
            is_string($row['expiresAt'] ?? null) ? $row['expiresAt'] : '',
            is_string($row['source'] ?? null) ? $row['source'] : '',
            is_string($row['helloassoOrderId'] ?? null) ? $row['helloassoOrderId'] : null,
            is_string($row['adminNote'] ?? null) ? $row['adminNote'] : null,
        );
    }
}
