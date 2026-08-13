<?php

declare(strict_types=1);

namespace App\Identity\Application\Query;

/**
 * One line of a user's audit timeline (story 36.5), already resolved for display: the counterpart is
 * named, not left as a bare id.
 */
final readonly class AdminUserActivityEntry
{
    public const string TYPE_ROLE_CHANGED = 'role_changed';
    public const string TYPE_ROLE_CHANGE_PERFORMED = 'role_change_performed';
    public const string TYPE_ADMIN_ACCOUNT_CREATED = 'admin_account_created';
    public const string TYPE_ADMIN_ACCOUNT_CREATED_BY = 'admin_account_created_by';
    public const string TYPE_ACCOUNT_DELETED = 'account_deleted';
    public const string TYPE_RUN_ADMIN_ACTION = 'run_admin_action';
    public const string TYPE_PRIVATE_EVENT_ACCESS = 'private_event_access';
    /** Story 36.6: an admin acted on this account (sessions revoked, email verified in their place). */
    public const string TYPE_ADMIN_ACTION_RECEIVED = 'admin_action_received';
    public const string TYPE_ADMIN_ACTION_PERFORMED = 'admin_action_performed';

    public function __construct(
        public string $type,
        public string $occurredAt,
        /** The other human involved, when there is one. */
        public ?string $counterpartId,
        public ?string $counterpartName,
        /** Role transition, for the two role types. */
        public ?string $previousRole,
        public ?string $newRole,
        /** Subject of the entry: a run title, an event title, a deletion reason, a run action. */
        public ?string $subject,
        public ?string $subjectId,
        /** Outcome of a private-event access attempt; null for every other type. */
        public ?bool $granted,
    ) {
    }
}
