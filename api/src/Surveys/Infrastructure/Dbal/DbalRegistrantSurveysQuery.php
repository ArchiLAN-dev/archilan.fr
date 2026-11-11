<?php

declare(strict_types=1);

namespace App\Surveys\Infrastructure\Dbal;

use App\Registrations\Application\Query\RegistrantSurveysQueryInterface;
use App\Surveys\Domain\Enum\SurveyAudience;
use App\Surveys\Domain\Enum\SurveyStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/** Story 53.2: the surveys a registrant's checklist mentions. A participation means the survey is answered (51.2). */
final readonly class DbalRegistrantSurveysQuery implements RegistrantSurveysQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function hasOpenRegistrationSurvey(string $eventId, \DateTimeImmutable $now): bool
    {
        return false !== $this->openSurveys($eventId, SurveyAudience::Registration, $now)
            ->select('1')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
    }

    public function countPendingForRegistrant(string $eventId, string $userId, \DateTimeImmutable $now): int
    {
        $answered = $this->connection->createQueryBuilder()
            ->select('1')
            ->from('survey_participation', 'p')
            ->where('p.survey_id = s.id')
            ->andWhere('p.user_id = :user');

        $count = $this->openSurveys($eventId, SurveyAudience::Registrants, $now)
            ->select('COUNT(*)')
            ->andWhere('NOT EXISTS ('.$answered->getSQL().')')
            ->setParameter('user', $userId)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    private function openSurveys(string $eventId, SurveyAudience $audience, \DateTimeImmutable $now): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder();

        return $qb
            ->from('survey', 's')
            ->where($qb->expr()->eq('s.event_id', ':event'))
            ->andWhere($qb->expr()->eq('s.status', ':published'))
            ->andWhere($qb->expr()->eq('s.audience', ':audience'))
            ->andWhere($qb->expr()->lte('s.opens_at', ':now'))
            ->andWhere($qb->expr()->gt('s.closes_at', ':now'))
            ->setParameter('event', $eventId)
            ->setParameter('published', SurveyStatus::Published->value)
            ->setParameter('audience', $audience->value)
            ->setParameter('now', $now->format('Y-m-d H:i:sP'));
    }
}
