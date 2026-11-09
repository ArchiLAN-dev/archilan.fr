<?php

declare(strict_types=1);

namespace App\Surveys\Infrastructure\Dbal;

use App\Registrations\Domain\Entity\Registration;
use App\Surveys\Application\Query\SurveyResultsQueryInterface;
use App\Surveys\Domain\Enum\SurveyStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * @phpstan-import-type LabelledAnswer from SurveyResultsQueryInterface
 * @phpstan-import-type MyAnswer from \App\Surveys\Application\Query\MemberSurveyQueryInterface
 */
final readonly class DbalSurveyResultsQuery implements SurveyResultsQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function tally(string $surveyId): array
    {
        $qb = $this->connection->createQueryBuilder();
        $respondents = $qb
            ->select('COUNT(*)')
            ->from('survey_participation', 'p')
            ->where($qb->expr()->eq('p.survey_id', ':survey'))
            ->setParameter('survey', $surveyId)
            ->executeQuery()
            ->fetchOne();

        $perQuestion = [];
        $qb = $this->answers($surveyId);
        $rows = $qb
            ->select('a.question_id', 'COUNT(DISTINCT a.response_id) AS n')
            ->groupBy('a.question_id')
            ->executeQuery()
            ->fetchAllAssociative();
        foreach ($rows as $row) {
            $perQuestion[self::string($row['question_id'])] = self::int($row['n']);
        }

        $perOption = [];
        $qb = $this->answers($surveyId);
        $rows = $qb
            ->select('a.option_id', 'COUNT(DISTINCT a.response_id) AS n')
            ->andWhere($qb->expr()->isNotNull('a.option_id'))
            ->groupBy('a.option_id')
            ->executeQuery()
            ->fetchAllAssociative();
        foreach ($rows as $row) {
            $perOption[self::string($row['option_id'])] = self::int($row['n']);
        }

        $ratings = [];
        $qb = $this->answers($surveyId);
        $rows = $qb
            ->select('a.question_id', 'a.rating', 'COUNT(*) AS n')
            ->andWhere($qb->expr()->isNotNull('a.rating'))
            ->groupBy('a.question_id', 'a.rating')
            ->executeQuery()
            ->fetchAllAssociative();
        foreach ($rows as $row) {
            $ratings[self::string($row['question_id'])][self::int($row['rating'])] = self::int($row['n']);
        }

        return ['respondents' => self::int($respondents), 'perQuestion' => $perQuestion, 'perOption' => $perOption, 'ratings' => $ratings];
    }

    public function texts(string $surveyId): array
    {
        $qb = $this->answers($surveyId);
        $rows = $qb
            ->select('a.question_id', 'a.text')
            ->andWhere($qb->expr()->isNotNull('a.text'))
            ->orderBy('LOWER(a.text)')
            ->addOrderBy('a.text')
            ->executeQuery()
            ->fetchAllAssociative();

        $texts = [];
        foreach ($rows as $row) {
            $texts[self::string($row['question_id'])][] = self::string($row['text']);
        }

        return $texts;
    }

    public function respondents(string $surveyId, string $eventId): array
    {
        $registration = $this->connection->createQueryBuilder()
            ->select('r.id')
            ->from('registration', 'r')
            ->where('r.event_id = :event')
            ->andWhere('r.user_id = p.user_id')
            ->orderBy('CASE WHEN r.status = :reserved THEN 0 ELSE 1 END')
            ->addOrderBy('r.created_at', 'DESC')
            ->setMaxResults(1);

        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('p.user_id', 'u.display_name', 'p.responded_at')
            ->addSelect('('.$registration->getSQL().') AS registration_id')
            ->from('survey_participation', 'p')
            ->innerJoin('p', $this->connection->quoteSingleIdentifier('user'), 'u', 'u.id = p.user_id')
            ->where($qb->expr()->eq('p.survey_id', ':survey'))
            ->setParameter('survey', $surveyId)
            ->setParameter('event', $eventId)
            ->setParameter('reserved', Registration::STATUS_RESERVED)
            ->orderBy('LOWER(u.display_name)')
            ->addOrderBy('p.user_id')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(fn (array $row): array => [
            'userId' => self::string($row['user_id']),
            'displayName' => self::string($row['display_name']),
            'registrationId' => is_string($row['registration_id']) ? $row['registration_id'] : null,
            'respondedAt' => $this->moment($row['responded_at']),
        ], $rows);
    }

    public function nominativeAnswers(string $surveyId): array
    {
        $qb = $this->answers($surveyId);
        $rows = $qb
            ->select('r.user_id', 'a.question_id', 'a.option_id', 'a.text', 'a.rating')
            ->innerJoin('a', 'survey_question', 'q', 'q.id = a.question_id')
            ->leftJoin('a', 'survey_option', 'o', 'o.id = a.option_id')
            ->andWhere($qb->expr()->isNotNull('r.user_id'))
            ->orderBy('q.position')
            ->addOrderBy('q.id')
            ->addOrderBy('o.position')
            ->addOrderBy('a.id')
            ->executeQuery()
            ->fetchAllAssociative();

        /** @var array<string, array<string, MyAnswer>> $byUser */
        $byUser = [];
        foreach ($rows as $row) {
            $userId = self::string($row['user_id']);
            $questionId = self::string($row['question_id']);
            $byUser[$userId][$questionId] ??= ['questionId' => $questionId, 'optionIds' => [], 'text' => null, 'rating' => null];
            if (is_string($row['option_id'])) {
                $byUser[$userId][$questionId]['optionIds'][] = $row['option_id'];
            }
            if (is_string($row['text'])) {
                $byUser[$userId][$questionId]['text'] = $row['text'];
            }
            if (is_numeric($row['rating'])) {
                $byUser[$userId][$questionId]['rating'] = (int) $row['rating'];
            }
        }

        return array_map(array_values(...), $byUser);
    }

    public function registrant(string $eventId, string $registrationId): ?string
    {
        $qb = $this->connection->createQueryBuilder();
        $userId = $qb
            ->select('r.user_id')
            ->from('registration', 'r')
            ->where($qb->expr()->eq('r.id', ':id'))
            ->andWhere($qb->expr()->eq('r.event_id', ':event'))
            ->setParameter('id', $registrationId)
            ->setParameter('event', $eventId)
            ->executeQuery()
            ->fetchOne();

        return is_string($userId) ? $userId : null;
    }

    public function answeredBy(string $eventId, string $userId): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('s.id AS survey_id', 's.title', 'p.responded_at', 'q.id AS question_id', 'q.kind', 'q.label AS question')
            ->addSelect('o.label AS choice', 'a.text', 'a.rating')
            ->from('survey', 's')
            ->innerJoin('s', 'survey_participation', 'p', 'p.survey_id = s.id AND p.user_id = :user')
            ->innerJoin('s', 'survey_response', 'r', 'r.survey_id = s.id AND r.user_id = :user')
            ->innerJoin('r', 'survey_answer', 'a', 'a.response_id = r.id')
            ->innerJoin('a', 'survey_question', 'q', 'q.id = a.question_id')
            ->leftJoin('a', 'survey_option', 'o', 'o.id = a.option_id')
            ->where($qb->expr()->eq('s.event_id', ':event'))
            ->andWhere($qb->expr()->eq('s.status', ':published'))
            ->andWhere('s.anonymous = false')
            ->setParameter('user', $userId)
            ->setParameter('event', $eventId)
            ->setParameter('published', SurveyStatus::Published->value)
            ->orderBy('p.responded_at', 'DESC')
            ->addOrderBy('s.id')
            ->addOrderBy('q.position')
            ->addOrderBy('q.id')
            ->addOrderBy('o.position')
            ->addOrderBy('a.id')
            ->executeQuery()
            ->fetchAllAssociative();

        /** @var array<string, array{surveyId: string, title: string, respondedAt: string, answers: array<string, LabelledAnswer>}> $surveys */
        $surveys = [];
        foreach ($rows as $row) {
            $surveyId = self::string($row['survey_id']);
            $questionId = self::string($row['question_id']);
            $surveys[$surveyId] ??= [
                'surveyId' => $surveyId,
                'title' => self::string($row['title']),
                'respondedAt' => $this->moment($row['responded_at']),
                'answers' => [],
            ];
            $surveys[$surveyId]['answers'][$questionId] ??= [
                'question' => self::string($row['question']),
                'kind' => self::string($row['kind']),
                'choices' => [],
                'text' => null,
                'rating' => null,
            ];
            if (is_string($row['choice'])) {
                $surveys[$surveyId]['answers'][$questionId]['choices'][] = $row['choice'];
            }
            if (is_string($row['text'])) {
                $surveys[$surveyId]['answers'][$questionId]['text'] = $row['text'];
            }
            if (is_numeric($row['rating'])) {
                $surveys[$surveyId]['answers'][$questionId]['rating'] = (int) $row['rating'];
            }
        }

        return array_values(array_map(static fn (array $survey): array => [
            'surveyId' => $survey['surveyId'],
            'title' => $survey['title'],
            'respondedAt' => $survey['respondedAt'],
            'answers' => array_values($survey['answers']),
        ], $surveys));
    }

    /** The answers of a survey, joined to their response (`r`); the caller selects. */
    private function answers(string $surveyId): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder();

        return $qb
            ->from('survey_answer', 'a')
            ->innerJoin('a', 'survey_response', 'r', 'r.id = a.response_id')
            ->where($qb->expr()->eq('r.survey_id', ':survey'))
            ->setParameter('survey', $surveyId);
    }

    private function moment(mixed $value): string
    {
        return new \DateTimeImmutable(self::string($value))->format(\DateTimeInterface::ATOM);
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : (is_int($value) ? (string) $value : '');
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }
}
