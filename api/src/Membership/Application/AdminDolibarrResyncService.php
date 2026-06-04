<?php

declare(strict_types=1);

namespace App\Membership\Application;

use App\Membership\Application\Message\SyncMemberToDolibarrMessage;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class AdminDolibarrResyncService
{
    public function __construct(
        private MembershipAllIdsQueryInterface $membershipIds,
        private MessageBusInterface $bus,
    ) {
    }

    public function dispatchAll(): int
    {
        $ids = $this->membershipIds->execute();

        foreach ($ids as $id) {
            $this->bus->dispatch(new SyncMemberToDolibarrMessage($id));
        }

        return count($ids);
    }
}
