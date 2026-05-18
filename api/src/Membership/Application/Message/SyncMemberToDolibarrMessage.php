<?php

declare(strict_types=1);

namespace App\Membership\Application\Message;

final readonly class SyncMemberToDolibarrMessage
{
    public function __construct(public string $membershipId)
    {
    }
}
