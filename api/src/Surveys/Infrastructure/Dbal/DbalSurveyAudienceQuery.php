<?php

declare(strict_types=1);

namespace App\Surveys\Infrastructure\Dbal;

use App\Registrations\Domain\Entity\Registration;
use App\Sessions\Domain\Entity\Session;
use App\Surveys\Application\Query\SurveyAudienceQueryInterface;
use App\Surveys\Domain\Enum\SurveyAudience;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Story 51.2. A session counts as launched once it reached `running`: it is running, paused (`idle`, `restarting`),
 * stopped, crashed or finished. A session that failed before running never had players.
 */
final readonly class DbalSurveyAudienceQuery implements SurveyAudienceQueryInterface
{
    private const array LAUNCHED = [
        Session::STATUS_RUNNING,
        Session::STATUS_IDLE,
        Session::STATUS_RESTARTING,
        Session::STATUS_STOPPED,
        Session::STATUS_CRASHED,
        Session::STATUS_FINISHED,
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function includes(SurveyAudience $audience, string $eventId, string $userId): bool
    {
        $qb = $this->registration($eventId, $userId);
        if (SurveyAudience::Participants === $audience) {
            $qb->andWhere($qb->expr()->isNotNull('r.submitted_at'));
            if ($this->anySessionLaunched($eventId)) {
                $qb->innerJoin('r', 'session_slot', 'slot', 'slot.registration_id = r.id')
                    ->innerJoin('slot', 'session', 's', 's.id = slot.session_id AND s.event_id = r.event_id')
                    ->andWhere($qb->expr()->in('s.status', ':launched'))
                    ->setParameter('launched', self::LAUNCHED, ArrayParameterType::STRING);
            }
        }

        return false !== $qb->setMaxResults(1)->executeQuery()->fetchOne();
    }

    private function registration(string $eventId, string $userId): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder();

        return $qb
            ->select('1')
            ->from('registration', 'r')
            ->where($qb->expr()->eq('r.event_id', ':event'))
            ->andWhere($qb->expr()->eq('r.user_id', ':user'))
            ->andWhere($qb->expr()->eq('r.status', ':reserved'))
            ->setParameter('event', $eventId)
            ->setParameter('user', $userId)
            ->setParameter('reserved', Registration::STATUS_RESERVED);
    }

    private function anySessionLaunched(string $eventId): bool
    {
        $qb = $this->connection->createQueryBuilder();

        return false !== $qb
            ->select('1')
            ->from('session', 's')
            ->where($qb->expr()->eq('s.event_id', ':event'))
            ->andWhere($qb->expr()->in('s.status', ':launched'))
            ->setParameter('event', $eventId)
            ->setParameter('launched', self::LAUNCHED, ArrayParameterType::STRING)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
    }
}
