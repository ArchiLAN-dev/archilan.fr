<?php

declare(strict_types=1);

namespace App\Surveys\Infrastructure\Dbal;

use App\Surveys\Application\Port\SurveyAnswerCountInterface;
use Doctrine\DBAL\Connection;

/** Whether an option was chosen in a stored answer (story 51.2), anonymous answers included. */
final readonly class DbalSurveyAnswerCount implements SurveyAnswerCountInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function optionHasAnswers(string $optionId): bool
    {
        $qb = $this->connection->createQueryBuilder();

        return false !== $qb
            ->select('1')
            ->from('survey_answer', 'a')
            ->where($qb->expr()->eq('a.option_id', ':option'))
            ->setParameter('option', $optionId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
    }
}
