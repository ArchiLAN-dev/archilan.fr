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
        $qb = $this->audience($audience, $eventId)->select('1');
        $qb->andWhere($qb->expr()->eq('r.user_id', ':user'))->setParameter('user', $userId);

        return false !== $qb->setMaxResults(1)->executeQuery()->fetchOne();
    }

    public function members(SurveyAudience $audience, string $eventId): array
    {
        $rows = $this->audience($audience, $eventId)
            ->select('r.id AS registration_id', 'r.user_id', 'u.display_name')
            ->innerJoin('r', $this->connection->quoteSingleIdentifier('user'), 'u', 'u.id = r.user_id')
            ->orderBy('LOWER(u.display_name)')
            ->addOrderBy('r.id')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'userId' => is_string($row['user_id']) ? $row['user_id'] : '',
            'registrationId' => is_string($row['registration_id']) ? $row['registration_id'] : '',
            'displayName' => is_string($row['display_name']) ? $row['display_name'] : '',
        ], $rows);
    }

    public function unanswered(SurveyAudience $audience, string $eventId, string $surveyId): array
    {
        $answered = $this->connection->createQueryBuilder()
            ->select('1')
            ->from('survey_participation', 'p')
            ->where('p.survey_id = :survey')
            ->andWhere('p.user_id = r.user_id');
        $qb = $this->audience($audience, $eventId);
        $rows = $qb
            ->select('r.user_id', 'u.display_name', 'u.email')
            ->innerJoin('r', $this->connection->quoteSingleIdentifier('user'), 'u', 'u.id = r.user_id')
            ->andWhere($qb->expr()->isNull('u.deleted_at'))
            ->andWhere('NOT EXISTS ('.$answered->getSQL().')')
            ->setParameter('survey', $surveyId)
            ->orderBy('r.user_id')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'userId' => is_string($row['user_id']) ? $row['user_id'] : '',
            'displayName' => is_string($row['display_name']) ? $row['display_name'] : '',
            'email' => is_string($row['email']) ? $row['email'] : '',
        ], $rows);
    }

    /** The active registrations of the event that the audience takes in; the caller selects. */
    private function audience(SurveyAudience $audience, string $eventId): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->from('registration', 'r')
            ->where($qb->expr()->eq('r.event_id', ':event'))
            ->andWhere($qb->expr()->eq('r.status', ':reserved'))
            ->setParameter('event', $eventId)
            ->setParameter('reserved', Registration::STATUS_RESERVED);
        if (SurveyAudience::Participants === $audience) {
            $qb->andWhere($qb->expr()->isNotNull('r.submitted_at'));
            if ($this->anySessionLaunched($eventId)) {
                $launched = $this->connection->createQueryBuilder()
                    ->select('1')
                    ->from('session_slot', 'slot')
                    ->innerJoin('slot', 'session', 's', 's.id = slot.session_id')
                    ->where('slot.registration_id = r.id')
                    ->andWhere('s.event_id = r.event_id')
                    ->andWhere('s.status IN (:launched)');
                $qb->andWhere('EXISTS ('.$launched->getSQL().')')
                    ->setParameter('launched', self::LAUNCHED, ArrayParameterType::STRING);
            }
        }

        return $qb;
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
