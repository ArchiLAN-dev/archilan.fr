<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\Registrations\Domain\Entity\Registration;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;
use Doctrine\DBAL\Connection;

/** Story 51.2: a member sees the surveys asked of them and answers them. */
final class MemberSurveyTest extends FunctionalTestCase
{
    private const string OPEN = '2026-01-01T00:00:00+00:00';
    private const string CLOSE = '2030-01-01T00:00:00+00:00';

    public function testAMemberAnswersAndChangesANominativeSurvey(): void
    {
        $admin = $this->admin();
        $eventId = $this->eventId();
        $member = $this->createUser('member@example.org');
        $this->createRegistration($eventId, $member->getId());
        $survey = $this->publishedSurvey($admin, $eventId);

        $this->loginAs($member);
        self::assertSame([['id' => $survey['id'], 'title' => 'Quand viens-tu ?', 'audience' => 'registrants', 'anonymous' => false, 'closesAt' => self::CLOSE, 'answered' => false]], $this->mySurveys($eventId));

        $form = $this->form($survey['id']);
        self::assertSame([], $form['myAnswers']);
        self::assertFalse($form['answered']);
        self::assertIsArray($form['questions']);
        self::assertCount(2, $form['questions']);

        $this->answer($survey['id'], [
            ['questionId' => $survey['days'], 'optionIds' => [$survey['saturday'], $survey['sunday']]],
            ['questionId' => $survey['word'], 'text' => ' Hâte ! '],
        ]);
        $this->answer($survey['id'], [['questionId' => $survey['days'], 'optionIds' => [$survey['sunday']]]]);

        $form = $this->form($survey['id']);
        self::assertTrue($form['answered']);
        self::assertSame([['questionId' => $survey['days'], 'optionIds' => [$survey['sunday']], 'text' => null, 'rating' => null]], $form['myAnswers']);
        self::assertTrue($this->mySurveys($eventId)[0]['answered'] ?? null);
        self::assertSame(1, $this->rows('survey_response'));
        self::assertSame(1, $this->rows('survey_participation'));

        // An option somebody chose is no longer renamed.
        $this->loginAs($admin);
        $this->client->jsonRequest('PATCH', '/api/v1/admin/surveys/'.$survey['id'].'/options/'.$survey['sunday'], ['label' => 'Lundi']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('survey_option_answered', $this->errorCode());
        $this->client->jsonRequest('PATCH', '/api/v1/admin/surveys/'.$survey['id'].'/options/'.$survey['saturday'], ['label' => 'Samedi matin']);
        self::assertResponseStatusCodeSame(204);
    }

    public function testAnAnonymousAnswerIsGivenOnceAndTiedToNobody(): void
    {
        $admin = $this->admin();
        $eventId = $this->eventId();
        $member = $this->createUser('member@example.org');
        $this->createRegistration($eventId, $member->getId());
        $survey = $this->publishedSurvey($admin, $eventId, anonymous: true);

        $this->loginAs($member);
        $this->answer($survey['id'], [['questionId' => $survey['days'], 'optionIds' => [$survey['saturday']]]]);

        $form = $this->form($survey['id']);
        self::assertTrue($form['answered']);
        self::assertNull($form['myAnswers']);
        $this->client->jsonRequest('PUT', '/api/v1/surveys/'.$survey['id'].'/my-response', ['answers' => [['questionId' => $survey['days'], 'optionIds' => [$survey['sunday']]]]]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('survey_already_answered', $this->errorCode());

        $connection = $this->connection();
        self::assertSame([null], $connection->createQueryBuilder()->select('user_id')->from('survey_response')->executeQuery()->fetchFirstColumn());
        $day = $connection->createQueryBuilder()->select('submitted_on')->from('survey_response')->executeQuery()->fetchOne();
        self::assertIsString($day);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $day);
        self::assertSame([$member->getId()], $connection->createQueryBuilder()->select('user_id')->from('survey_participation')->executeQuery()->fetchFirstColumn());
    }

    public function testEachAudienceReachesItsMembers(): void
    {
        $admin = $this->admin();
        $eventId = $this->eventId();
        $confirmed = $this->createUser('confirmed@example.org');
        $pending = $this->createUser('pending@example.org');
        $cancelled = $this->createUser('cancelled@example.org');
        $stranger = $this->createUser('stranger@example.org');
        $confirmedRegistration = $this->createRegistration($eventId, $confirmed->getId());
        $confirmedRegistration->confirm(new \DateTimeImmutable('2026-05-02T10:00:00+00:00'));
        $this->createRegistration($eventId, $pending->getId());
        $this->createRegistration($eventId, $cancelled->getId(), Registration::STATUS_CANCELLED);
        $this->entityManager->flush();

        $registrants = $this->publishedSurvey($admin, $eventId)['id'];
        $participants = $this->publishedSurvey($admin, $eventId, audience: 'participants')['id'];

        // No session ever ran: every confirmed registration counts as a participant.
        self::assertSame([$registrants, $participants], $this->openSurveyIds($confirmed, $eventId));
        self::assertSame([$registrants], $this->openSurveyIds($pending, $eventId));
        self::assertSame([], $this->openSurveyIds($cancelled, $eventId));
        self::assertSame([], $this->openSurveyIds($stranger, $eventId));

        // Once a session ran, a participant is a confirmed registration with a slot in it.
        $session = Session::createRunning('session-1', $eventId, 'localhost', 38281, null, null, new \DateTimeImmutable('2026-06-05T10:00:00+00:00'));
        $this->entityManager->persist($session);
        $this->entityManager->flush();
        self::assertSame([$registrants], $this->openSurveyIds($confirmed, $eventId));
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $confirmedRegistration->getId(), 'game-1', 'Confirmed', 1));
        $this->entityManager->flush();
        self::assertSame([$registrants, $participants], $this->openSurveyIds($confirmed, $eventId));

        $this->loginAs($stranger);
        $this->client->request('GET', '/api/v1/surveys/'.$registrants.'/my-response');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('survey_not_eligible', $this->errorCode());
    }

    public function testADraftOrAClosedSurveyIsNotAnswered(): void
    {
        $admin = $this->admin();
        $eventId = $this->eventId();
        $member = $this->createUser('member@example.org');
        $this->createRegistration($eventId, $member->getId());

        $this->loginAs($admin);
        $draft = $this->createSurvey($eventId, [], self::OPEN, self::CLOSE)['id'];
        $closed = $this->publishedSurvey($admin, $eventId, closesAt: '2026-02-01T00:00:00+00:00')['id'];
        $future = $this->publishedSurvey($admin, $eventId, opensAt: '2029-01-01T00:00:00+00:00')['id'];

        $this->loginAs($member);
        self::assertSame([], $this->mySurveys($eventId));
        $this->client->request('GET', '/api/v1/surveys/'.$draft.'/my-response');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('survey_not_found', $this->errorCode());
        foreach ([$closed, $future] as $surveyId) {
            $this->client->jsonRequest('PUT', '/api/v1/surveys/'.$surveyId.'/my-response', ['answers' => []]);
            self::assertResponseStatusCodeSame(409);
            self::assertSame('survey_closed', $this->errorCode());
        }
    }

    public function testInvalidAnswersAreRefused(): void
    {
        $admin = $this->admin();
        $eventId = $this->eventId();
        $member = $this->createUser('member@example.org');
        $this->createRegistration($eventId, $member->getId());
        $survey = $this->publishedSurvey($admin, $eventId);

        $this->loginAs($member);
        $refusals = [
            [['answers' => [['questionId' => $survey['word'], 'text' => 'Seul']]], 422, 'survey_answer_required'],
            [['answers' => [['questionId' => $survey['days'], 'optionIds' => ['unknown']]]], 422, 'survey_answer_invalid'],
            [['answers' => [['questionId' => $survey['days'], 'optionIds' => 'sat']]], 400, 'invalid_json'],
            [['answers' => 'all'], 400, 'invalid_json'],
        ];
        foreach ($refusals as [$body, $status, $code]) {
            $this->client->jsonRequest('PUT', '/api/v1/surveys/'.$survey['id'].'/my-response', $body);
            self::assertResponseStatusCodeSame($status, $code);
            self::assertSame($code, $this->errorCode());
        }
        self::assertSame(0, $this->rows('survey_participation'));
    }

    public function testEveryRouteNeedsASession(): void
    {
        $this->client->request('GET', '/api/v1/events/any/my-surveys');
        self::assertResponseStatusCodeSame(401);
        $this->client->request('GET', '/api/v1/surveys/any/my-response');
        self::assertResponseStatusCodeSame(401);
        $this->client->jsonRequest('PUT', '/api/v1/surveys/any/my-response', ['answers' => []]);
        self::assertResponseStatusCodeSame(401);
    }

    private function admin(): User
    {
        return $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Jean');
    }

    private function eventId(): string
    {
        return $this->createEvent(
            'ArchiLAN 4',
            new \DateTimeImmutable('2027-06-05T09:00:00+00:00'),
            new \DateTimeImmutable('2027-06-06T18:00:00+00:00'),
        )->getId();
    }

    /**
     * A survey with a required multiple choice (Saturday, Sunday, and a retired Friday) and an optional text.
     *
     * @return array{id: string, days: string, saturday: string, sunday: string, word: string}
     */
    private function publishedSurvey(User $admin, string $eventId, bool $anonymous = false, string $audience = 'registrants', string $opensAt = self::OPEN, string $closesAt = self::CLOSE): array
    {
        $this->loginAs($admin);
        $survey = $this->createSurvey($eventId, ['anonymous' => $anonymous, 'audience' => $audience], $opensAt, $closesAt);
        $this->client->request('POST', '/api/v1/admin/surveys/'.$survey['id'].'/publish');
        self::assertResponseStatusCodeSame(204);
        $this->client->request('DELETE', '/api/v1/admin/surveys/'.$survey['id'].'/options/'.$survey['friday']);
        self::assertResponseStatusCodeSame(204);

        return ['id' => $survey['id'], 'days' => $survey['days'], 'saturday' => $survey['saturday'], 'sunday' => $survey['sunday'], 'word' => $survey['word']];
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array{id: string, days: string, saturday: string, sunday: string, friday: string, word: string}
     */
    private function createSurvey(string $eventId, array $fields, string $opensAt, string $closesAt): array
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/events/'.$eventId.'/surveys', ['title' => 'Quand viens-tu ?', 'opensAt' => $opensAt, 'closesAt' => $closesAt, ...$fields]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->createdId();
        $this->client->jsonRequest('POST', '/api/v1/admin/surveys/'.$id.'/questions', ['kind' => 'multiple', 'label' => 'Quels jours ?', 'required' => true, 'options' => ['Samedi', 'Dimanche', 'Vendredi']]);
        self::assertResponseStatusCodeSame(201);
        $days = $this->createdId();
        $this->client->jsonRequest('POST', '/api/v1/admin/surveys/'.$id.'/questions', ['kind' => 'text', 'label' => 'Un mot ?']);
        self::assertResponseStatusCodeSame(201);
        $word = $this->createdId();

        $options = $this->connection()->createQueryBuilder()->select('id')->from('survey_option')->where('question_id = :q')->setParameter('q', $days)->orderBy('position')->executeQuery()->fetchFirstColumn();
        self::assertCount(3, $options);
        [$saturday, $sunday, $friday] = $options;
        self::assertIsString($saturday);
        self::assertIsString($sunday);
        self::assertIsString($friday);

        return ['id' => $id, 'days' => $days, 'saturday' => $saturday, 'sunday' => $sunday, 'friday' => $friday, 'word' => $word];
    }

    /** @return list<string> */
    private function openSurveyIds(User $member, string $eventId): array
    {
        $this->loginAs($member);
        // Ids sort in time: sorted, they are in the order the surveys were created.
        $ids = array_values(array_filter(array_column($this->mySurveys($eventId), 'id'), is_string(...)));
        sort($ids);

        return $ids;
    }

    /** @return list<array<mixed>> */
    private function mySurveys(string $eventId): array
    {
        $this->client->request('GET', '/api/v1/events/'.$eventId.'/my-surveys');
        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertTrue(array_is_list($data));
        $surveys = [];
        foreach ($data as $survey) {
            self::assertIsArray($survey);
            $surveys[] = $survey;
        }

        return $surveys;
    }

    /** @return array<mixed> */
    private function form(string $surveyId): array
    {
        $this->client->request('GET', '/api/v1/surveys/'.$surveyId.'/my-response');
        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }

    /** @param list<array<string, mixed>> $answers */
    private function answer(string $surveyId, array $answers): void
    {
        $this->client->jsonRequest('PUT', '/api/v1/surveys/'.$surveyId.'/my-response', ['answers' => $answers]);
        self::assertResponseStatusCodeSame(204);
    }

    private function createdId(): string
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsString($data['id']);

        return $data['id'];
    }

    private function errorCode(): string
    {
        $error = $this->decodedJsonResponse()['error'] ?? null;
        self::assertIsArray($error);
        self::assertIsString($error['code'] ?? null);

        return $error['code'];
    }

    private function rows(string $table): int
    {
        $count = $this->connection()->createQueryBuilder()->select('COUNT(*)')->from($table)->executeQuery()->fetchOne();

        return is_numeric($count) ? (int) $count : -1;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
