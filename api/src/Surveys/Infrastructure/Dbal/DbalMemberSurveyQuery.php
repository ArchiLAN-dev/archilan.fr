<?php

declare(strict_types=1);

namespace App\Surveys\Infrastructure\Dbal;

use App\Surveys\Application\Query\MemberSurveyQueryInterface;
use App\Surveys\Domain\Enum\SurveyStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * @phpstan-import-type SurveyHeader from MemberSurveyQueryInterface
 * @phpstan-import-type FormOption from MemberSurveyQueryInterface
 */
final readonly class DbalMemberSurveyQuery implements MemberSurveyQueryInterface
{
    private const array HEADER_COLUMNS = ['s.id', 's.event_id', 's.title', 's.intro', 's.audience', 's.anonymous', 's.status', 's.opens_at', 's.closes_at'];

    public function __construct(private Connection $connection)
    {
    }

    public function openForEvent(string $eventId, \DateTimeImmutable $now): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select(...self::HEADER_COLUMNS)
            ->from('survey', 's')
            ->where($qb->expr()->eq('s.event_id', ':event'))
            ->andWhere($qb->expr()->eq('s.status', ':published'))
            ->andWhere($qb->expr()->lte('s.opens_at', ':now'))
            ->andWhere($qb->expr()->gt('s.closes_at', ':now'))
            ->setParameter('event', $eventId)
            ->setParameter('published', SurveyStatus::Published->value)
            ->setParameter('now', $now->format('Y-m-d H:i:sP'))
            ->orderBy('s.closes_at')
            ->addOrderBy('s.id')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(self::headerOf(...), $rows);
    }

    public function header(string $surveyId): ?array
    {
        $qb = $this->connection->createQueryBuilder();
        $row = $qb
            ->select(...self::HEADER_COLUMNS)
            ->from('survey', 's')
            ->where($qb->expr()->eq('s.id', ':id'))
            ->setParameter('id', $surveyId)
            ->executeQuery()
            ->fetchAssociative();

        return false === $row ? null : self::headerOf($row);
    }

    public function questions(string $surveyId): array
    {
        $qb = $this->connection->createQueryBuilder();
        $questions = $qb
            ->select('q.id', 'q.kind', 'q.label', 'q.required')
            ->from('survey_question', 'q')
            ->where($qb->expr()->eq('q.survey_id', ':id'))
            ->setParameter('id', $surveyId)
            ->orderBy('q.position')
            ->addOrderBy('q.id')
            ->executeQuery()
            ->fetchAllAssociative();
        $options = $this->activeOptions(array_map(static fn (array $q): string => self::string($q['id']), $questions));

        return array_map(static fn (array $q): array => [
            'id' => self::string($q['id']),
            'kind' => self::string($q['kind']),
            'label' => self::string($q['label']),
            'required' => self::bool($q['required']),
            'options' => $options[self::string($q['id'])] ?? [],
        ], $questions);
    }

    public function answered(array $surveyIds, string $userId): array
    {
        if ([] === $surveyIds) {
            return [];
        }
        $qb = $this->connection->createQueryBuilder();
        $ids = $qb
            ->select('p.survey_id')
            ->from('survey_participation', 'p')
            ->where($qb->expr()->in('p.survey_id', ':ids'))
            ->andWhere($qb->expr()->eq('p.user_id', ':user'))
            ->setParameter('ids', $surveyIds, ArrayParameterType::STRING)
            ->setParameter('user', $userId)
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_filter($ids, is_string(...)));
    }

    public function myAnswers(string $surveyId, string $userId): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('a.question_id', 'a.option_id', 'a.text', 'a.rating')
            ->from('survey_answer', 'a')
            ->innerJoin('a', 'survey_response', 'r', 'r.id = a.response_id')
            ->where($qb->expr()->eq('r.survey_id', ':survey'))
            ->andWhere($qb->expr()->eq('r.user_id', ':user'))
            ->setParameter('survey', $surveyId)
            ->setParameter('user', $userId)
            ->orderBy('a.id')
            ->executeQuery()
            ->fetchAllAssociative();

        $byQuestion = [];
        foreach ($rows as $row) {
            $questionId = self::string($row['question_id']);
            $byQuestion[$questionId] ??= ['questionId' => $questionId, 'optionIds' => [], 'text' => null, 'rating' => null];
            if (is_string($row['option_id'])) {
                $byQuestion[$questionId]['optionIds'][] = $row['option_id'];
            }
            if (is_string($row['text'])) {
                $byQuestion[$questionId]['text'] = $row['text'];
            }
            if (is_numeric($row['rating'])) {
                $byQuestion[$questionId]['rating'] = (int) $row['rating'];
            }
        }

        return array_values($byQuestion);
    }

    /**
     * @param list<string> $questionIds
     *
     * @return array<string, list<FormOption>>
     */
    private function activeOptions(array $questionIds): array
    {
        if ([] === $questionIds) {
            return [];
        }
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('o.id', 'o.question_id', 'o.label')
            ->from('survey_option', 'o')
            ->where($qb->expr()->in('o.question_id', ':ids'))
            ->andWhere($qb->expr()->isNull('o.retired_at'))
            ->setParameter('ids', $questionIds, ArrayParameterType::STRING)
            ->orderBy('o.position')
            ->addOrderBy('o.id')
            ->executeQuery()
            ->fetchAllAssociative();

        $byQuestion = [];
        foreach ($rows as $row) {
            $byQuestion[self::string($row['question_id'])][] = ['id' => self::string($row['id']), 'label' => self::string($row['label'])];
        }

        return $byQuestion;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return SurveyHeader
     */
    private static function headerOf(array $row): array
    {
        return [
            'id' => self::string($row['id']),
            'eventId' => self::string($row['event_id']),
            'title' => self::string($row['title']),
            'intro' => self::string($row['intro']),
            'audience' => self::string($row['audience']),
            'anonymous' => self::bool($row['anonymous']),
            'published' => SurveyStatus::Published->value === $row['status'],
            'opensAt' => new \DateTimeImmutable(self::string($row['opens_at'])),
            'closesAt' => new \DateTimeImmutable(self::string($row['closes_at'])),
        ];
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function bool(mixed $value): bool
    {
        return true === $value || 1 === $value || '1' === $value || 't' === $value;
    }
}
