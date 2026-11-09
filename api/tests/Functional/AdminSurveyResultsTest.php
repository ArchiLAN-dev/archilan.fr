<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;

/** Story 51.4: the admins read what the members answered, and who has not answered yet. */
final class AdminSurveyResultsTest extends FunctionalTestCase
{
    private User $admin;
    private string $eventId;

    /** @var array<string, array{user: User, registration: string}> */
    private array $members = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Jean');
        $this->eventId = $this->createEvent(
            'ArchiLAN 4',
            new \DateTimeImmutable('2027-06-05T09:00:00+00:00'),
            new \DateTimeImmutable('2027-06-06T18:00:00+00:00'),
        )->getId();
        foreach (['Bob', 'alice', 'Chloé', 'Dave'] as $name) {
            $user = $this->createUser(strtolower($name).'@example.org', ['ROLE_USER'], $name);
            $this->members[$name] = ['user' => $user, 'registration' => $this->createRegistration($this->eventId, $user->getId())->getId()];
        }
    }

    public function testTheResultsOfANominativeSurvey(): void
    {
        $survey = $this->publishedSurvey(anonymous: false);
        $this->answerAs('alice', $survey, 'yes', 5, 'zut alors');
        $this->answerAs('Bob', $survey, 'no', 4, 'Alors on danse');
        $this->answerAs('Chloé', $survey, 'yes', null, null);

        $this->loginAs($this->admin);
        $results = $this->get('/api/v1/admin/surveys/'.$survey['id'].'/results');
        self::assertSame(3, $results['respondentCount']);
        self::assertSame(4, $results['audienceSize']);
        self::assertFalse($results['textsHidden']);
        [$coming, $mark, $word] = $this->questions($results);

        self::assertSame(3, $coming['answerCount']);
        self::assertSame([
            ['id' => $survey['yes'], 'label' => 'Oui', 'retired' => false, 'count' => 2],
            ['id' => $survey['no'], 'label' => 'Non', 'retired' => false, 'count' => 1],
        ], $coming['options']);
        self::assertNull($coming['rating']);
        self::assertNull($coming['texts']);

        self::assertSame(2, $mark['answerCount']);
        self::assertSame(['average' => 4.5, 'counts' => [0, 0, 0, 1, 1]], $mark['rating']);

        self::assertSame(['Alors on danse', 'zut alors'], $word['texts']);

        $respondents = $this->rowsOf($this->get('/api/v1/admin/surveys/'.$survey['id'].'/respondents'));
        self::assertSame(['alice', 'Bob', 'Chloé'], array_column($respondents, 'displayName'));
        self::assertSame($this->members['Bob']['registration'], $respondents[1]['registrationId'] ?? null);
        self::assertSame([
            ['questionId' => $survey['coming'], 'optionIds' => [$survey['no']], 'text' => null, 'rating' => null],
            ['questionId' => $survey['mark'], 'optionIds' => [], 'text' => null, 'rating' => 4],
            ['questionId' => $survey['word'], 'optionIds' => [], 'text' => 'Alors on danse', 'rating' => null],
        ], $respondents[1]['answers'] ?? null);

        self::assertSame(
            [['userId' => $this->members['Dave']['user']->getId(), 'registrationId' => $this->members['Dave']['registration'], 'displayName' => 'Dave']],
            $this->get('/api/v1/admin/surveys/'.$survey['id'].'/non-respondents'),
        );

        $answered = $this->rowsOf($this->get('/api/v1/admin/events/'.$this->eventId.'/registrations/'.$this->members['Bob']['registration'].'/surveys'));
        self::assertCount(1, $answered);
        self::assertSame('Viens-tu ?', $answered[0]['title'] ?? null);
        self::assertSame([
            ['question' => 'Tu viens ?', 'kind' => 'single', 'choices' => ['Non'], 'text' => null, 'rating' => null],
            ['question' => 'Ta note', 'kind' => 'rating', 'choices' => [], 'text' => null, 'rating' => 4],
            ['question' => 'Un mot ?', 'kind' => 'text', 'choices' => [], 'text' => 'Alors on danse', 'rating' => null],
        ], $answered[0]['answers'] ?? null);
        self::assertSame([], $this->get('/api/v1/admin/events/'.$this->eventId.'/registrations/'.$this->members['Dave']['registration'].'/surveys'));
    }

    public function testAnAnonymousSurveyHidesItsTextsBelowThreeResponsesAndHasNoPerMemberView(): void
    {
        $survey = $this->publishedSurvey(anonymous: true);
        $this->answerAs('alice', $survey, 'yes', 3, 'zèbre');
        $this->answerAs('Bob', $survey, 'no', null, 'Abricot');

        $this->loginAs($this->admin);
        $results = $this->get('/api/v1/admin/surveys/'.$survey['id'].'/results');
        self::assertSame(2, $results['respondentCount']);
        self::assertTrue($results['textsHidden']);
        self::assertNull($this->questions($results)[2]['texts']);
        self::assertSame(1, $this->rowsOf($this->questions($results)[0]['options'])[0]['count'] ?? null);

        $this->client->request('GET', '/api/v1/admin/surveys/'.$survey['id'].'/respondents');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('survey_anonymous', $this->errorCode());

        // Who answered is always known: the non-respondents are named.
        self::assertSame(['Chloé', 'Dave'], array_column($this->get('/api/v1/admin/surveys/'.$survey['id'].'/non-respondents'), 'displayName'));
        self::assertSame([], $this->get('/api/v1/admin/events/'.$this->eventId.'/registrations/'.$this->members['alice']['registration'].'/surveys'));

        $this->answerAs('Chloé', $survey, 'yes', null, 'moustique');
        $this->loginAs($this->admin);
        $results = $this->get('/api/v1/admin/surveys/'.$survey['id'].'/results');
        self::assertFalse($results['textsHidden']);
        self::assertSame(['Abricot', 'moustique', 'zèbre'], $this->questions($results)[2]['texts']);
    }

    public function testTheRoutesAreForAdminsOnly(): void
    {
        $survey = $this->publishedSurvey(anonymous: false);
        $routes = [
            '/api/v1/admin/surveys/'.$survey['id'].'/results',
            '/api/v1/admin/surveys/'.$survey['id'].'/respondents',
            '/api/v1/admin/surveys/'.$survey['id'].'/non-respondents',
            '/api/v1/admin/events/'.$this->eventId.'/registrations/'.$this->members['Bob']['registration'].'/surveys',
        ];

        $this->client->getCookieJar()->clear();
        foreach ($routes as $route) {
            $this->client->request('GET', $route);
            self::assertResponseStatusCodeSame(401, $route);
        }
        $this->loginAs($this->members['Bob']['user']);
        foreach ($routes as $route) {
            $this->client->request('GET', $route);
            self::assertResponseStatusCodeSame(403, $route);
        }

        $this->loginAs($this->admin);
        $this->client->request('GET', '/api/v1/admin/surveys/unknown/results');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/v1/admin/events/'.$this->eventId.'/registrations/unknown/surveys');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * A survey with a single choice (Oui, Non), an optional rating and an optional text.
     *
     * @return array{id: string, coming: string, yes: string, no: string, mark: string, word: string}
     */
    private function publishedSurvey(bool $anonymous): array
    {
        $this->loginAs($this->admin);
        $this->client->jsonRequest('POST', '/api/v1/admin/events/'.$this->eventId.'/surveys', [
            'title' => 'Viens-tu ?',
            'anonymous' => $anonymous,
            'opensAt' => '2026-01-01T00:00:00+00:00',
            'closesAt' => '2030-01-01T00:00:00+00:00',
        ]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->createdId();
        $coming = $this->addQuestion($id, ['kind' => 'single', 'label' => 'Tu viens ?', 'required' => true, 'options' => ['Oui', 'Non']]);
        $mark = $this->addQuestion($id, ['kind' => 'rating', 'label' => 'Ta note']);
        $word = $this->addQuestion($id, ['kind' => 'text', 'label' => 'Un mot ?']);
        $this->client->request('POST', '/api/v1/admin/surveys/'.$id.'/publish');
        self::assertResponseStatusCodeSame(204);

        $survey = $this->get('/api/v1/admin/surveys/'.$id);
        $options = $this->rowsOf($this->questions($survey)[0]['options']);
        $yes = $options[0]['id'] ?? null;
        $no = $options[1]['id'] ?? null;
        self::assertIsString($yes);
        self::assertIsString($no);

        return ['id' => $id, 'coming' => $coming, 'yes' => $yes, 'no' => $no, 'mark' => $mark, 'word' => $word];
    }

    /** @param array<string, mixed> $question */
    private function addQuestion(string $surveyId, array $question): string
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/surveys/'.$surveyId.'/questions', $question);
        self::assertResponseStatusCodeSame(201);

        return $this->createdId();
    }

    /** @param array{id: string, coming: string, yes: string, no: string, mark: string, word: string} $survey */
    private function answerAs(string $name, array $survey, string $choice, ?int $rating, ?string $text): void
    {
        $answers = [['questionId' => $survey['coming'], 'optionIds' => [$survey[$choice]]]];
        if (null !== $rating) {
            $answers[] = ['questionId' => $survey['mark'], 'rating' => $rating];
        }
        if (null !== $text) {
            $answers[] = ['questionId' => $survey['word'], 'text' => $text];
        }
        $this->loginAs($this->members[$name]['user']);
        $this->client->jsonRequest('PUT', '/api/v1/surveys/'.$survey['id'].'/my-response', ['answers' => $answers]);
        self::assertResponseStatusCodeSame(204);
    }

    /**
     * @param array<mixed> $survey
     *
     * @return list<array<mixed>>
     */
    private function questions(array $survey): array
    {
        $questions = $survey['questions'] ?? null;
        self::assertIsArray($questions);
        self::assertCount(3, $questions);
        $list = [];
        foreach ($questions as $question) {
            self::assertIsArray($question);
            $list[] = $question;
        }

        return $list;
    }

    /** @return list<array<mixed>> */
    private function rowsOf(mixed $value): array
    {
        self::assertIsArray($value);
        $rows = [];
        foreach ($value as $row) {
            self::assertIsArray($row);
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return array<mixed> */
    private function get(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful($url);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
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
}
