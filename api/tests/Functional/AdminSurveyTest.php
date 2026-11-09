<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;

/** Story 51.1: the surveys of an event, as the admins write, publish and revise them. */
final class AdminSurveyTest extends FunctionalTestCase
{
    public function testTheFullLifeOfASurvey(): void
    {
        $this->loginAs($this->admin());
        $eventId = $this->eventId();

        $surveyId = $this->create($eventId, [
            'title' => 'Quand viens-tu ?',
            'intro' => 'Pour préparer la salle.',
            'opensAt' => '2026-11-10T09:00:00+01:00',
            'closesAt' => '2026-11-20T18:00:00+01:00',
        ]);
        $days = $this->addQuestion($surveyId, ['kind' => 'multiple', 'label' => 'Quels jours ?', 'required' => true, 'options' => ['Samedi', 'Dimanche']]);
        $sleep = $this->addQuestion($surveyId, ['kind' => 'single', 'label' => 'Tu dors sur place ?']);
        $this->client->jsonRequest('POST', '/api/v1/admin/surveys/'.$surveyId.'/questions/'.$sleep.'/options', ['label' => 'Oui']);
        self::assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', '/api/v1/admin/surveys/'.$surveyId.'/questions/'.$sleep.'/options', ['label' => 'Non']);
        self::assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('PUT', '/api/v1/admin/surveys/'.$surveyId.'/questions/order', ['ids' => [$sleep, $days]]);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('POST', '/api/v1/admin/surveys/'.$surveyId.'/publish');
        self::assertResponseStatusCodeSame(204);

        $friday = $this->addOption($surveyId, $days, 'Vendredi soir');
        $this->client->jsonRequest('PATCH', '/api/v1/admin/surveys/'.$surveyId.'/options/'.$friday, ['label' => 'Vendredi']);
        self::assertResponseStatusCodeSame(204);
        $sunday = $this->survey($surveyId)['questions'][1]['options'][1]['id'];
        $this->client->request('DELETE', '/api/v1/admin/surveys/'.$surveyId.'/options/'.$sunday);
        self::assertResponseStatusCodeSame(204);
        $this->client->jsonRequest('PATCH', '/api/v1/admin/surveys/'.$surveyId, ['title' => 'Quand viens-tu à l\'ArchiLAN ?', 'sendEmail' => false]);
        self::assertResponseStatusCodeSame(204);

        $survey = $this->survey($surveyId);
        self::assertSame($eventId, $survey['eventId']);
        self::assertSame('Quand viens-tu à l\'ArchiLAN ?', $survey['title']);
        self::assertSame('Pour préparer la salle.', $survey['intro']);
        self::assertSame('registrants', $survey['audience']);
        self::assertFalse($survey['anonymous']);
        self::assertFalse($survey['sendEmail']);
        self::assertSame('published', $survey['status']);
        self::assertSame('2026-11-10T08:00:00+00:00', $survey['opensAt']);
        self::assertNotNull($survey['publishedAt']);
        self::assertSame([$sleep, $days], array_column($survey['questions'], 'id'));
        self::assertSame(['single', 'multiple'], array_column($survey['questions'], 'kind'));
        self::assertSame([false, true], array_column($survey['questions'], 'required'));
        self::assertSame(['Samedi', 'Dimanche', 'Vendredi'], array_column($survey['questions'][1]['options'], 'label'));
        self::assertSame([false, true, false], array_column($survey['questions'][1]['options'], 'retired'));

        $this->client->request('GET', '/api/v1/admin/events/'.$eventId.'/surveys');
        self::assertResponseIsSuccessful();
        $list = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($list);
        self::assertCount(1, $list);
        self::assertIsArray($list[0]);
        self::assertSame($surveyId, $list[0]['id']);
        self::assertSame('published', $list[0]['status']);
        self::assertSame(2, $list[0]['questionCount']);
    }

    public function testADraftTakesItsDefaultsFromTheEvent(): void
    {
        $this->loginAs($this->admin());
        $eventId = $this->eventId();

        $before = $this->survey($this->create($eventId, ['title' => 'Avant']));
        self::assertSame('registrants', $before['audience']);
        self::assertTrue($before['sendEmail']);
        self::assertSame('draft', $before['status']);
        self::assertSame('2027-06-05T09:00:00+00:00', $before['closesAt']);

        $after = $this->survey($this->create($eventId, ['title' => 'Après', 'audience' => 'participants', 'anonymous' => true]));
        self::assertTrue($after['anonymous']);
        self::assertSame('2027-06-06T18:00:00+00:00', $after['opensAt']);
        self::assertSame('2027-06-13T18:00:00+00:00', $after['closesAt']);
    }

    public function testADraftChangesFreelyAndIsDeletedWithItsQuestions(): void
    {
        $this->loginAs($this->admin());
        $surveyId = $this->create($this->eventId(), ['title' => 'Brouillon']);
        $first = $this->addQuestion($surveyId, ['kind' => 'single', 'label' => 'Choix ?', 'options' => ['A', 'B']]);
        $second = $this->addQuestion($surveyId, ['kind' => 'rating', 'label' => 'Ta note ?']);

        $this->client->jsonRequest('PATCH', '/api/v1/admin/surveys/'.$surveyId.'/questions/'.$first, ['kind' => 'text']);
        self::assertResponseStatusCodeSame(204);
        $this->client->jsonRequest('PATCH', '/api/v1/admin/surveys/'.$surveyId, ['audience' => 'participants', 'anonymous' => true]);
        self::assertResponseStatusCodeSame(204);
        $survey = $this->survey($surveyId);
        self::assertSame([], $survey['questions'][0]['options']);
        self::assertSame('participants', $survey['audience']);

        $this->client->request('DELETE', '/api/v1/admin/surveys/'.$surveyId.'/questions/'.$first);
        self::assertResponseStatusCodeSame(204);
        $questions = $this->survey($surveyId)['questions'];
        self::assertSame([$second], array_column($questions, 'id'));
        self::assertSame([0], array_column($questions, 'position'));

        $this->client->request('DELETE', '/api/v1/admin/surveys/'.$surveyId);
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/api/v1/admin/surveys/'.$surveyId);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('survey_not_found', $this->errorCode($this->decodedJsonResponse()));
    }

    public function testPublicationNeedsQuestionsAndChoices(): void
    {
        $this->loginAs($this->admin());
        $surveyId = $this->create($this->eventId(), ['title' => 'Vide']);

        $this->client->request('POST', '/api/v1/admin/surveys/'.$surveyId.'/publish');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('survey_questions_missing', $this->errorCode($this->decodedJsonResponse()));

        $this->addQuestion($surveyId, ['kind' => 'single', 'label' => 'Un seul choix ?', 'options' => ['Seul']]);
        $this->client->request('POST', '/api/v1/admin/surveys/'.$surveyId.'/publish');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('survey_question_options_missing', $this->errorCode($this->decodedJsonResponse()));
    }

    public function testAPublishedSurveyKeepsWhatAnswersRelyOn(): void
    {
        $this->loginAs($this->admin());
        $surveyId = $this->create($this->eventId(), ['title' => 'Publié']);
        $questionId = $this->addQuestion($surveyId, ['kind' => 'single', 'label' => 'Choix ?', 'options' => ['A', 'B']]);
        $this->client->request('POST', '/api/v1/admin/surveys/'.$surveyId.'/publish');
        self::assertResponseStatusCodeSame(204);

        $refusals = [
            ['POST', '/api/v1/admin/surveys/'.$surveyId.'/publish', [], 'survey_already_published'],
            ['PATCH', '/api/v1/admin/surveys/'.$surveyId, ['anonymous' => true], 'survey_locked_field'],
            ['PATCH', '/api/v1/admin/surveys/'.$surveyId, ['audience' => 'participants'], 'survey_locked_field'],
            ['PATCH', '/api/v1/admin/surveys/'.$surveyId.'/questions/'.$questionId, ['kind' => 'multiple'], 'survey_question_locked'],
            ['DELETE', '/api/v1/admin/surveys/'.$surveyId.'/questions/'.$questionId, [], 'survey_question_locked'],
            ['DELETE', '/api/v1/admin/surveys/'.$surveyId, [], 'survey_published'],
        ];
        foreach ($refusals as [$method, $uri, $body, $code]) {
            $this->client->jsonRequest($method, $uri, $body);
            self::assertResponseStatusCodeSame(409, $method.' '.$uri);
            self::assertSame($code, $this->errorCode($this->decodedJsonResponse()));
        }
    }

    public function testInvalidInputIsRefused(): void
    {
        $this->loginAs($this->admin());
        $eventId = $this->eventId();
        $surveyId = $this->create($eventId, ['title' => 'Sondage']);
        $text = $this->addQuestion($surveyId, ['kind' => 'text', 'label' => 'Un mot ?']);
        $other = $this->create($eventId, ['title' => 'Autre']);
        $otherQuestion = $this->addQuestion($other, ['kind' => 'text', 'label' => 'Ailleurs ?']);

        $refusals = [
            ['POST', '/api/v1/admin/events/'.$eventId.'/surveys', ['title' => ''], 422, 'survey_title_invalid'],
            ['POST', '/api/v1/admin/events/'.$eventId.'/surveys', ['title' => 'T', 'audience' => 'everyone'], 422, 'survey_audience_invalid'],
            ['POST', '/api/v1/admin/events/'.$eventId.'/surveys', ['title' => 'T', 'opensAt' => 'demain'], 422, 'survey_period_invalid'],
            ['POST', '/api/v1/admin/events/'.$eventId.'/surveys', ['title' => 'T', 'opensAt' => '2026-12-01T00:00:00Z', 'closesAt' => '2026-11-01T00:00:00Z'], 422, 'survey_period_invalid'],
            ['POST', '/api/v1/admin/events/'.$eventId.'/surveys', ['title' => 42], 400, 'invalid_json'],
            ['POST', '/api/v1/admin/events/unknown/surveys', ['title' => 'T'], 404, 'event_not_found'],
            ['POST', '/api/v1/admin/surveys/'.$surveyId.'/questions', ['kind' => 'slider', 'label' => 'Q'], 422, 'survey_question_kind_invalid'],
            ['POST', '/api/v1/admin/surveys/'.$surveyId.'/questions', ['kind' => 'text', 'label' => 'Q', 'options' => ['A']], 422, 'survey_question_has_no_options'],
            ['POST', '/api/v1/admin/surveys/'.$surveyId.'/questions/'.$text.'/options', ['label' => 'A'], 422, 'survey_question_has_no_options'],
            ['PUT', '/api/v1/admin/surveys/'.$surveyId.'/questions/order', ['ids' => [$text, $text]], 422, 'survey_order_invalid'],
            ['PATCH', '/api/v1/admin/surveys/'.$surveyId.'/questions/'.$otherQuestion, ['label' => 'Volée'], 404, 'survey_question_not_found'],
            ['PATCH', '/api/v1/admin/surveys/'.$surveyId.'/options/unknown', ['label' => 'A'], 404, 'survey_option_not_found'],
            ['GET', '/api/v1/admin/events/unknown/surveys', [], 404, 'event_not_found'],
        ];
        foreach ($refusals as [$method, $uri, $body, $status, $code]) {
            $this->client->jsonRequest($method, $uri, $body);
            self::assertResponseStatusCodeSame($status, $method.' '.$uri.' '.$code);
            self::assertSame($code, $this->errorCode($this->decodedJsonResponse()));
        }
    }

    public function testEveryRouteIsForAdminsOnly(): void
    {
        $this->loginAs($this->admin());
        $eventId = $this->eventId();
        $surveyId = $this->create($eventId, ['title' => 'Sondage']);
        $questionId = $this->addQuestion($surveyId, ['kind' => 'single', 'label' => 'Choix ?', 'options' => ['A', 'B']]);
        $optionId = $this->survey($surveyId)['questions'][0]['options'][0]['id'];
        $routes = [
            ['GET', '/api/v1/admin/events/'.$eventId.'/surveys'],
            ['POST', '/api/v1/admin/events/'.$eventId.'/surveys'],
            ['GET', '/api/v1/admin/surveys/'.$surveyId],
            ['PATCH', '/api/v1/admin/surveys/'.$surveyId],
            ['DELETE', '/api/v1/admin/surveys/'.$surveyId],
            ['POST', '/api/v1/admin/surveys/'.$surveyId.'/publish'],
            ['POST', '/api/v1/admin/surveys/'.$surveyId.'/questions'],
            ['PUT', '/api/v1/admin/surveys/'.$surveyId.'/questions/order'],
            ['PATCH', '/api/v1/admin/surveys/'.$surveyId.'/questions/'.$questionId],
            ['DELETE', '/api/v1/admin/surveys/'.$surveyId.'/questions/'.$questionId],
            ['POST', '/api/v1/admin/surveys/'.$surveyId.'/questions/'.$questionId.'/options'],
            ['PUT', '/api/v1/admin/surveys/'.$surveyId.'/questions/'.$questionId.'/options/order'],
            ['PATCH', '/api/v1/admin/surveys/'.$surveyId.'/options/'.$optionId],
            ['DELETE', '/api/v1/admin/surveys/'.$surveyId.'/options/'.$optionId],
        ];

        $this->client->getCookieJar()->clear();
        foreach ($routes as [$method, $uri]) {
            $this->client->jsonRequest($method, $uri, ['title' => 'T']);
            self::assertResponseStatusCodeSame(401, $method.' '.$uri);
        }

        $this->loginAs($this->createUser('member@example.org'));
        foreach ($routes as [$method, $uri]) {
            $this->client->jsonRequest($method, $uri, ['title' => 'T']);
            self::assertResponseStatusCodeSame(403, $method.' '.$uri);
        }
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

    /** @param array<string, mixed> $body */
    private function create(string $eventId, array $body): string
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/events/'.$eventId.'/surveys', $body);
        self::assertResponseStatusCodeSame(201);

        return $this->createdId();
    }

    /** @param array<string, mixed> $body */
    private function addQuestion(string $surveyId, array $body): string
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/surveys/'.$surveyId.'/questions', $body);
        self::assertResponseStatusCodeSame(201);

        return $this->createdId();
    }

    private function addOption(string $surveyId, string $questionId, string $label): string
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/surveys/'.$surveyId.'/questions/'.$questionId.'/options', ['label' => $label]);
        self::assertResponseStatusCodeSame(201);

        return $this->createdId();
    }

    /** @param array<mixed> $body */
    private function errorCode(array $body): string
    {
        self::assertIsArray($body['error'] ?? null);
        self::assertIsString($body['error']['code'] ?? null);

        return $body['error']['code'];
    }

    private function createdId(): string
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsString($data['id']);

        return $data['id'];
    }

    /**
     * @return array{eventId: mixed, title: mixed, intro: mixed, audience: mixed, anonymous: mixed, sendEmail: mixed, status: mixed, opensAt: mixed, closesAt: mixed, publishedAt: mixed, questions: list<array{id: string, kind: string, required: bool, position: int, options: list<array{id: string, label: string, retired: bool}>}>}
     */
    private function survey(string $surveyId): array
    {
        $this->client->request('GET', '/api/v1/admin/surveys/'.$surveyId);
        self::assertResponseIsSuccessful();
        $survey = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($survey);
        self::assertIsArray($survey['questions']);
        $questions = [];
        foreach ($survey['questions'] as $question) {
            self::assertIsArray($question);
            self::assertIsString($question['id']);
            self::assertIsString($question['kind']);
            self::assertIsBool($question['required']);
            self::assertIsInt($question['position']);
            self::assertIsArray($question['options']);
            $options = [];
            foreach ($question['options'] as $option) {
                self::assertIsArray($option);
                self::assertIsString($option['id']);
                self::assertIsString($option['label']);
                self::assertIsBool($option['retired']);
                $options[] = ['id' => $option['id'], 'label' => $option['label'], 'retired' => $option['retired']];
            }
            $questions[] = ['id' => $question['id'], 'kind' => $question['kind'], 'required' => $question['required'], 'position' => $question['position'], 'options' => $options];
        }

        return [
            'eventId' => $survey['eventId'] ?? null,
            'title' => $survey['title'] ?? null,
            'intro' => $survey['intro'] ?? null,
            'audience' => $survey['audience'] ?? null,
            'anonymous' => $survey['anonymous'] ?? null,
            'sendEmail' => $survey['sendEmail'] ?? null,
            'status' => $survey['status'] ?? null,
            'opensAt' => $survey['opensAt'] ?? null,
            'closesAt' => $survey['closesAt'] ?? null,
            'publishedAt' => $survey['publishedAt'] ?? null,
            'questions' => $questions,
        ];
    }
}
