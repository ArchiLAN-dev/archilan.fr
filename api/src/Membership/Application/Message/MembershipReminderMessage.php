<?php

declare(strict_types=1);

namespace App\Membership\Application\Message;

final readonly class MembershipReminderMessage
{
    public function __construct(
        public string $membershipId,
        public int $daysLeft,
    ) {
    }
}
