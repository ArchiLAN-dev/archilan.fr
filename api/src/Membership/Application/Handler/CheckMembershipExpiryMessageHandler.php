<?php

declare(strict_types=1);

namespace App\Membership\Application\Handler;

use App\Membership\Application\Message\CheckMembershipExpiryMessage;
use App\Membership\Application\Message\ExpireMembershipMessage;
use App\Membership\Application\Message\MembershipReminderMessage;
use App\Membership\Domain\Membership;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class CheckMembershipExpiryMessageHandler
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
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
        $qb = $this->connection->createQueryBuilder();
        $ids = $qb
            ->select('m.id')
            ->from('memberships', 'm')
            ->where($qb->expr()->eq('m.status', ':status'))
            ->andWhere($qb->expr()->lte('m.expires_at', ':now'))
            ->setParameter('status', 'active')
            ->setParameter('now', $now, Types::DATETIMETZ_IMMUTABLE)
            ->executeQuery()
            ->fetchFirstColumn();

        foreach ($ids as $id) {
            if (!is_string($id)) {
                continue;
            }

            $this->bus->dispatch(new ExpireMembershipMessage($id));
        }
    }

    private function dispatchReminders(\DateTimeImmutable $now, int $daysLeft): void
    {
        $deadline = $now->add(new \DateInterval('P'.$daysLeft.'D'));
        $reminderField = 30 === $daysLeft ? 'm.reminder_30_sent_at' : 'm.reminder_7_sent_at';

        $qb = $this->connection->createQueryBuilder();
        $ids = $qb
            ->select('m.id')
            ->from('memberships', 'm')
            ->where($qb->expr()->eq('m.status', ':status'))
            ->andWhere($qb->expr()->gt('m.expires_at', ':now'))
            ->andWhere($qb->expr()->lte('m.expires_at', ':deadline'))
            ->andWhere($qb->expr()->isNull($reminderField))
            ->setParameter('status', 'active')
            ->setParameter('now', $now, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('deadline', $deadline, Types::DATETIMETZ_IMMUTABLE)
            ->executeQuery()
            ->fetchFirstColumn();

        foreach ($ids as $id) {
            if (!is_string($id)) {
                continue;
            }

            $membership = $this->entityManager->find(Membership::class, $id);
            if (!$membership instanceof Membership) {
                continue;
            }

            if (30 === $daysLeft) {
                $membership->markReminder30Sent($now);
            } else {
                $membership->markReminder7Sent($now);
            }

            $this->entityManager->flush();

            $this->bus->dispatch(new MembershipReminderMessage($id, $daysLeft));
        }
    }
}
