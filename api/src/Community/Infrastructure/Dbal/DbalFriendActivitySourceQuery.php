<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\FriendActivitySourceQueryInterface;
use App\Community\Domain\Entity\Notification;
use App\Shared\Infrastructure\Dbal\DbalSlotPlayerSource;
use Doctrine\DBAL\Connection;

final readonly class DbalFriendActivitySourceQuery implements FriendActivitySourceQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function sessionPlayers(string $sessionId): array
    {
        return $this->players($sessionId, null);
    }

    public function slotPlayers(string $sessionId, string $slotName): array
    {
        return $this->players($sessionId, $slotName);
    }

    public function upcomingPublicEventTitle(string $eventId, \DateTimeImmutable $now): ?string
    {
        $qb = $this->connection->createQueryBuilder();
        $title = $qb
            ->select('e.title')
            ->from('event', 'e')
            ->where($qb->expr()->eq('e.id', ':id'))
            ->andWhere('e.is_public = TRUE')
            ->andWhere($qb->expr()->gt('e.starts_at', ':now'))
            ->setParameter('id', $eventId)
            ->setParameter('now', $now->format(\DateTimeInterface::ATOM))
            ->executeQuery()
            ->fetchOne();

        return is_string($title) ? $title : null;
    }

    public function alertActorsSince(string $recipientId, \DateTimeImmutable $since): array
    {
        $actors = [];
        foreach ($this->alertsSince($recipientId, $since, null) as $actor) {
            $actors[] = is_string($actor) ? $actor : null;
        }

        return $actors;
    }

    public function hasAlertFromSince(string $recipientId, string $actorId, \DateTimeImmutable $since): bool
    {
        return [] !== $this->alertsSince($recipientId, $since, $actorId);
    }

    /**
     * @return list<mixed> the actor of each alert
     */
    private function alertsSince(string $recipientId, \DateTimeImmutable $since, ?string $actorId): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select("n.payload::jsonb ->> 'fromUserId' AS actor_id")
            ->from('community_notification', 'n')
            ->where($qb->expr()->eq('n.recipient_id', ':recipient'))
            ->andWhere($qb->expr()->eq('n.type', ':type'))
            ->andWhere($qb->expr()->gte('n.created_at', ':since'))
            ->setParameter('recipient', $recipientId)
            ->setParameter('type', Notification::TYPE_FRIEND_ACTIVITY)
            ->setParameter('since', $since->format(\DateTimeInterface::ATOM));
        if (null !== $actorId) {
            $qb->andWhere("n.payload::jsonb ->> 'fromUserId' = :actor")->setParameter('actor', $actorId);
        }

        return $qb->executeQuery()->fetchFirstColumn();
    }

    /**
     * @return list<string>
     */
    private function players(string $sessionId, ?string $slotName): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select('DISTINCT p.'.DbalSlotPlayerSource::USER_COLUMN.' AS user_id')
            ->from('session_slot', 'ss')
            ->join('ss', DbalSlotPlayerSource::expression('session_slot', 'registration'), 'p', 'p.'.DbalSlotPlayerSource::SLOT_COLUMN.' = ss.id')
            ->where($qb->expr()->eq('ss.session_id', ':session'))
            ->setParameter('session', $sessionId);
        if (null !== $slotName) {
            $qb->andWhere($qb->expr()->eq('ss.slot_name', ':slot'))->setParameter('slot', $slotName);
        }

        $ids = [];
        foreach ($qb->executeQuery()->fetchFirstColumn() as $id) {
            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
