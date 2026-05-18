<?php

declare(strict_types=1);

namespace App\Membership\Application\Message;

final readonly class MembershipExpiredNotificationMessage
{
    public function __construct(
        public string $userId,
    ) {
    }
}
