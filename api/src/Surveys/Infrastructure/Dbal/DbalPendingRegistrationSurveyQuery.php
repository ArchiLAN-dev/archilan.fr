<?php

declare(strict_types=1);

namespace App\Surveys\Infrastructure\Dbal;

use App\Registrations\Application\Query\PendingRegistrationSurveyQueryInterface;
use App\Surveys\Domain\Enum\SurveyAudience;
use App\Surveys\Domain\Enum\SurveyStatus;
use Doctrine\DBAL\Connection;

/**
 * Story 51.5. An answer was checked against the required questions when it was given (51.2): a participation means
 * the survey is answered.
 */
final readonly class DbalPendingRegistrationSurveyQuery implements PendingRegistrationSurveyQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function hasUnansweredRequired(string $eventId, string $userId, \DateTimeImmutable $now): bool
    {
        $required = $this->connection->createQueryBuilder()
            ->select('1')
            ->from('survey_question', 'q')
            ->where('q.survey_id = s.id')
            ->andWhere('q.required = true');
        $answered = $this->connection->createQueryBuilder()
            ->select('1')
            ->from('survey_participation', 'p')
            ->where('p.survey_id = s.id')
            ->andWhere('p.user_id = :user');

        $qb = $this->connection->createQueryBuilder();

        return false !== $qb
            ->select('1')
            ->from('survey', 's')
            ->where($qb->expr()->eq('s.event_id', ':event'))
            ->andWhere($qb->expr()->eq('s.status', ':published'))
            ->andWhere($qb->expr()->eq('s.audience', ':audience'))
            ->andWhere($qb->expr()->lte('s.opens_at', ':now'))
            ->andWhere($qb->expr()->gt('s.closes_at', ':now'))
            ->andWhere('EXISTS ('.$required->getSQL().')')
            ->andWhere('NOT EXISTS ('.$answered->getSQL().')')
            ->setParameter('event', $eventId)
            ->setParameter('user', $userId)
            ->setParameter('published', SurveyStatus::Published->value)
            ->setParameter('audience', SurveyAudience::Registration->value)
            ->setParameter('now', $now->format('Y-m-d H:i:sP'))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
    }
}
