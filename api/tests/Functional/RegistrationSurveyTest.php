<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;

/** Story 51.5: the organisers' required questions at registration come before the confirmation. */
final class RegistrationSurveyTest extends FunctionalTestCase
{
    private User $admin;
    private User $member;
    private string $eventId;
    private string $registrationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Jean');
        $this->member = $this->createUser('member@example.org');
        $this->eventId = $this->createEvent(
            'ArchiLAN 4',
            new \DateTimeImmutable('2027-06-05T09:00:00+00:00'),
            new \DateTimeImmutable('2027-06-06T18:00:00+00:00'),
        )->getId();
        $this->registrationId = $this->createRegistration($this->eventId, $this->member->getId())->getId();
    }

    public function testTheConfirmationWaitsForTheRequiredQuestionsThenGoesThrough(): void
    {
        $survey = $this->registrationSurvey(required: true);

        $this->loginAs($this->member);
        $this->client->jsonRequest('POST', '/api/v1/registrations/'.$this->registrationId.'/submit');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('registration_survey_incomplete', $this->errorCode());

        $mine = $this->get('/api/v1/events/'.$this->eventId.'/my-surveys');
        self::assertSame('registration', $mine[0]['audience'] ?? null);

        $this->client->jsonRequest('PUT', '/api/v1/surveys/'.$survey['id'].'/my-response', ['answers' => [['questionId' => $survey['question'], 'text' => 'Samedi matin']]]);
        self::assertResponseStatusCodeSame(204);
        $this->client->jsonRequest('POST', '/api/v1/registrations/'.$this->registrationId.'/submit');
        self::assertResponseIsSuccessful();
    }

    public function testOptionalQuestionsDraftsAndOtherAudiencesDoNotBlock(): void
    {
        $this->registrationSurvey(required: false);
        $this->registrationSurvey(required: true, publish: false);
        $this->registrationSurvey(required: true, audience: 'registrants');

        $this->loginAs($this->member);
        $this->client->jsonRequest('POST', '/api/v1/registrations/'.$this->registrationId.'/submit');
        self::assertResponseIsSuccessful();
    }

    public function testAConfirmedRegistrationIsNotBlockedByALaterSurvey(): void
    {
        $this->loginAs($this->member);
        $this->client->jsonRequest('POST', '/api/v1/registrations/'.$this->registrationId.'/submit');
        self::assertResponseIsSuccessful();

        $this->registrationSurvey(required: true);
        $this->loginAs($this->member);
        $this->client->jsonRequest('POST', '/api/v1/registrations/'.$this->registrationId.'/submit');
        self::assertResponseIsSuccessful();
    }

    public function testAnAdminRegistersAMemberWhateverTheQuestions(): void
    {
        $this->registrationSurvey(required: true);
        $newcomer = $this->createUser('newcomer@example.org');

        $this->loginAs($this->admin);
        $this->client->jsonRequest('POST', '/api/v1/admin/events/'.$this->eventId.'/registrations', ['userId' => $newcomer->getId(), 'acknowledgeWarnings' => true]);
        self::assertResponseStatusCodeSame(201);
    }

    /** @return array{id: string, question: string} */
    private function registrationSurvey(bool $required, bool $publish = true, string $audience = 'registration'): array
    {
        $this->loginAs($this->admin);
        $this->client->jsonRequest('POST', '/api/v1/admin/events/'.$this->eventId.'/surveys', [
            'title' => 'Quand arrives-tu ?',
            'audience' => $audience,
            'opensAt' => '2026-01-01T00:00:00+00:00',
            'closesAt' => '2030-01-01T00:00:00+00:00',
        ]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->createdId();
        $this->client->jsonRequest('POST', '/api/v1/admin/surveys/'.$id.'/questions', ['kind' => 'text', 'label' => 'Ton arrivée ?', 'required' => $required]);
        self::assertResponseStatusCodeSame(201);
        $question = $this->createdId();
        if ($publish) {
            $this->client->request('POST', '/api/v1/admin/surveys/'.$id.'/publish');
            self::assertResponseStatusCodeSame(204);
        }

        return ['id' => $id, 'question' => $question];
    }

    /** @return list<array<mixed>> */
    private function get(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $rows = [];
        foreach ($data as $row) {
            self::assertIsArray($row);
            $rows[] = $row;
        }

        return $rows;
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
