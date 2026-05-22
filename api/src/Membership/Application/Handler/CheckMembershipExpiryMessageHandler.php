<?php

declare(strict_types=1);

namespace App\Membership\Application\Handler;

use App\Membership\Application\MembershipExpiryCheckQueryInterface;
use App\Membership\Application\Message\CheckMembershipExpiryMessage;
use App\Membership\Application\Message\ExpireMembershipMessage;
use App\Membership\Application\Message\MembershipReminderMessage;
use App\Membership\Domain\Membership;
use App\Membership\Domain\MembershipRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class CheckMembershipExpiryMessageHandler
{
    public function __construct(
        private MembershipExpiryCheckQueryInterface $expiryCheck,
        private MembershipRepositoryInterface $memberships,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(CheckMembershipExpiryMessage $message): void
    {
        $now = new \DateTimeImmutable();

        $this->dispatchExpiries($now);
        $this->dispatchReminders($now, 30);
        $this->dispatchReminders($now, 7);
    }

    private function dispatchExpiries(\DateTimeImmutable $now): void
    {
        $ids = $this->expiryCheck->findExpiredActiveIds($now);

        foreach ($ids as $id) {
            $this->bus->dispatch(new ExpireMembershipMessage($id));
        }
    }

    private function dispatchReminders(\DateTimeImmutable $now, int $daysLeft): void
    {
        $ids = $this->expiryCheck->findPendingReminderIds($now, $daysLeft);

        foreach ($ids as $id) {
            $membership = $this->memberships->findById($id);
            if (!$membership instanceof Membership) {
                continue;
            }

            if (30 === $daysLeft) {
                $membership->markReminder30Sent($now);
            } else {
                $membership->markReminder7Sent($now);
            }

            $this->memberships->flush();

            $this->bus->dispatch(new MembershipReminderMessage($id, $daysLeft));
        }
    }
}
