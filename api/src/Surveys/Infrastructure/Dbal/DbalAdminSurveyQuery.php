<?php

declare(strict_types=1);

namespace App\Surveys\Infrastructure\Dbal;

use App\Surveys\Application\Query\AdminSurveyQueryInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * @phpstan-import-type OptionView from AdminSurveyQueryInterface
 */
final readonly class DbalAdminSurveyQuery implements AdminSurveyQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function forEvent(string $eventId): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('s.id', 's.title', 's.audience', 's.anonymous', 's.status', 's.opens_at', 's.closes_at')
            ->addSelect('(SELECT COUNT(*) FROM survey_question q WHERE q.survey_id = s.id) AS question_count')
            ->from('survey', 's')
            ->where($qb->expr()->eq('s.event_id', ':event'))
            ->setParameter('event', $eventId)
            ->orderBy('s.created_at', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(fn (array $row): array => [
            'id' => self::string($row['id']),
            'title' => self::string($row['title']),
            'audience' => self::string($row['audience']),
            'anonymous' => self::bool($row['anonymous']),
            'status' => self::string($row['status']),
            'opensAt' => $this->date($row['opens_at']),
            'closesAt' => $this->date($row['closes_at']),
            'questionCount' => self::int($row['question_count']),
        ], $rows);
    }

    public function survey(string $surveyId): ?array
    {
        $qb = $this->connection->createQueryBuilder();
        $row = $qb
            ->select('s.id', 's.event_id', 's.title', 's.intro', 's.audience', 's.anonymous', 's.send_email', 's.status')
            ->addSelect('s.opens_at', 's.closes_at', 's.published_at', 's.created_at', 's.updated_at')
            ->from('survey', 's')
            ->where($qb->expr()->eq('s.id', ':id'))
            ->setParameter('id', $surveyId)
            ->executeQuery()
            ->fetchAssociative();
        if (false === $row) {
            return null;
        }

        $qb = $this->connection->createQueryBuilder();
        $questions = $qb
            ->select('q.id', 'q.kind', 'q.label', 'q.required', 'q.position')
            ->from('survey_question', 'q')
            ->where($qb->expr()->eq('q.survey_id', ':id'))
            ->setParameter('id', $surveyId)
            ->orderBy('q.position')
            ->addOrderBy('q.id')
            ->executeQuery()
            ->fetchAllAssociative();
        $options = $this->options(array_map(static fn (array $q): string => self::string($q['id']), $questions));

        return [
            'id' => self::string($row['id']),
            'eventId' => self::string($row['event_id']),
            'title' => self::string($row['title']),
            'intro' => self::string($row['intro']),
            'audience' => self::string($row['audience']),
            'anonymous' => self::bool($row['anonymous']),
            'sendEmail' => self::bool($row['send_email']),
            'status' => self::string($row['status']),
            'opensAt' => $this->date($row['opens_at']),
            'closesAt' => $this->date($row['closes_at']),
            'publishedAt' => null === $row['published_at'] ? null : $this->date($row['published_at']),
            'createdAt' => $this->date($row['created_at']),
            'updatedAt' => $this->date($row['updated_at']),
            'questions' => array_map(static fn (array $q): array => [
                'id' => self::string($q['id']),
                'kind' => self::string($q['kind']),
                'label' => self::string($q['label']),
                'required' => self::bool($q['required']),
                'position' => self::int($q['position']),
                'options' => $options[self::string($q['id'])] ?? [],
            ], $questions),
        ];
    }

    /**
     * @param list<string> $questionIds
     *
     * @return array<string, list<OptionView>>
     */
    private function options(array $questionIds): array
    {
        if ([] === $questionIds) {
            return [];
        }
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('o.id', 'o.question_id', 'o.label', 'o.position', 'o.retired_at')
            ->from('survey_option', 'o')
            ->where($qb->expr()->in('o.question_id', ':ids'))
            ->setParameter('ids', $questionIds, ArrayParameterType::STRING)
            ->orderBy('o.position')
            ->addOrderBy('o.id')
            ->executeQuery()
            ->fetchAllAssociative();

        $byQuestion = [];
        foreach ($rows as $row) {
            $byQuestion[self::string($row['question_id'])][] = [
                'id' => self::string($row['id']),
                'label' => self::string($row['label']),
                'position' => self::int($row['position']),
                'retired' => null !== $row['retired_at'],
            ];
        }

        return $byQuestion;
    }

    private function date(mixed $value): string
    {
        return new \DateTimeImmutable(self::string($value))->format(\DateTimeInterface::ATOM);
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    private static function bool(mixed $value): bool
    {
        return true === $value || 1 === $value || '1' === $value || 't' === $value;
    }
}
