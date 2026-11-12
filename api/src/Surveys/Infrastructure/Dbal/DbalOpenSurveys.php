<?php

declare(strict_types=1);

namespace App\Surveys\Infrastructure\Dbal;

use App\Surveys\Domain\Enum\SurveyAudience;
use App\Surveys\Domain\Enum\SurveyStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * v0.66.1: what an open survey of an event is, written once: published, for this audience, opened and not yet closed.
 * Shared by the registration gate (51.5) and the registrant's checklist (53.2), so they never disagree. The builder
 * reads `survey` as `s` and has no select yet.
 */
final class DbalOpenSurveys
{
    public static function of(Connection $connection, string $eventId, SurveyAudience $audience, \DateTimeImmutable $now): QueryBuilder
    {
        $qb = $connection->createQueryBuilder();

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
