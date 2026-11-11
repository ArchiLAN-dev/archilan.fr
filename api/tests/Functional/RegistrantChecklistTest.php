<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Entity\Event;
use App\Identity\Domain\Entity\User;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Entity\RegistrationPresenceWindow;

/** Story 53.2: GET /registrations/{id}/checklist, what the registrant still has to do. */
final class RegistrantChecklistTest extends FunctionalTestCase
{
    private const string OPEN = '2026-01-01T00:00:00+00:00';
    private const string CLOSE = '2030-01-01T00:00:00+00:00';

    public function testAFreshRegistrationAsksToConfirm(): void
    {
        $member = $this->createUser('member@example.org');
        $event = $this->event();
        $registration = $this->createRegistration($event->getId(), $member->getId());
        $this->loginAs($member);

        $data = $this->checklist($registration->getId());

        self::assertSame('before', $data['phase']);
        self::assertSame([
            ['key' => 'confirm', 'state' => 'todo', 'required' => true],
            ['key' => 'presence', 'state' => 'optional', 'required' => false],
            ['key' => 'discord', 'state' => 'optional', 'required' => false],
        ], $data['items']);
    }

    public function testDoneStepsAreChecked(): void
    {
        $member = $this->createUser('member@example.org');
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $member->linkDiscord('123456789', 'member', $now);
        $event = $this->event();
        $registration = $this->createRegistration($event->getId(), $member->getId());
        $registration->confirm($now);
        $this->entityManager->persist(RegistrationPresenceWindow::create($registration->getId(), $event->getStartsAt(), $event->getEndsAt()));
        $this->entityManager->flush();
        $this->loginAs($member);

        $items = $this->checklist($registration->getId())['items'];

        foreach ($items as $item) {
            self::assertSame('done', $item['state'], sprintf('%s is not done', is_string($item['key']) ? $item['key'] : '?'));
        }
    }

    public function testAHelloAssoEventAsksForThePayment(): void
    {
        $member = $this->createUser('member@example.org');
        $event = $this->event();
        $event->linkHelloassoForm('archilan-4', new \DateTimeImmutable('2026-05-01T10:00:00+00:00'));
        $this->entityManager->flush();
        $registration = $this->createRegistration($event->getId(), $member->getId());
        $this->loginAs($member);

        $items = $this->checklist($registration->getId())['items'];

        self::assertContains(
            ['key' => 'payment', 'state' => 'todo', 'required' => true, 'detail' => ['found' => false, 'stale' => false]],
            $items,
        );
    }

    public function testGameSelectionAddsTheGamesLine(): void
    {
        $member = $this->createUser('member@example.org');
        $event = $this->createEvent('ArchiLAN 4', new \DateTimeImmutable('2027-06-05T09:00:00+00:00'), new \DateTimeImmutable('2027-06-06T18:00:00+00:00'), published: true, gameSelectionEnabled: true);
        $registration = $this->createRegistration($event->getId(), $member->getId());
        $this->loginAs($member);

        $items = $this->checklist($registration->getId())['items'];

        self::assertContains(['key' => 'games', 'state' => 'todo', 'required' => true], $items);
    }

    public function testOpenSurveysAppear(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $member = $this->createUser('member@example.org');
        $event = $this->event();
        $registration = $this->createRegistration($event->getId(), $member->getId());
        $this->publishSurvey($admin, $event->getId(), 'registrants');
        $this->publishSurvey($admin, $event->getId(), 'registration');
        $this->loginAs($member);

        $items = $this->checklist($registration->getId())['items'];

        self::assertContains(['key' => 'registration_questions', 'state' => 'todo', 'required' => true], $items);
        self::assertContains(['key' => 'surveys', 'state' => 'optional', 'required' => false, 'detail' => ['count' => 1]], $items);
    }

    public function testAnotherMemberIsRefused(): void
    {
        $owner = $this->createUser('owner@example.org');
        $other = $this->createUser('other@example.org');
        $registration = $this->createRegistration($this->event()->getId(), $owner->getId());
        $this->loginAs($other);

        $this->client->request('GET', '/api/v1/registrations/'.$registration->getId().'/checklist');

        self::assertResponseStatusCodeSame(403);
    }

    public function testACancelledOrUnknownRegistrationIsNotFound(): void
    {
        $member = $this->createUser('member@example.org');
        $registration = $this->createRegistration($this->event()->getId(), $member->getId(), Registration::STATUS_CANCELLED);
        $this->loginAs($member);

        $this->client->request('GET', '/api/v1/registrations/'.$registration->getId().'/checklist');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/v1/registrations/unknown/checklist');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAVisitorMustLogIn(): void
    {
        $member = $this->createUser('member@example.org');
        $registration = $this->createRegistration($this->event()->getId(), $member->getId());

        $this->client->request('GET', '/api/v1/registrations/'.$registration->getId().'/checklist');

        self::assertResponseStatusCodeSame(401);
    }

    private function event(): Event
    {
        return $this->createEvent('ArchiLAN 4', new \DateTimeImmutable('2027-06-05T09:00:00+00:00'), new \DateTimeImmutable('2027-06-06T18:00:00+00:00'), published: true);
    }

    /**
     * @return array{phase: string, items: list<array<mixed>>}
     */
    private function checklist(string $registrationId): array
    {
        $this->client->request('GET', '/api/v1/registrations/'.$registrationId.'/checklist');
        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsString($data['phase']);
        self::assertIsList($data['items']);

        $items = [];
        foreach ($data['items'] as $item) {
            self::assertIsArray($item);
            $items[] = $item;
        }

        return ['phase' => $data['phase'], 'items' => $items];
    }

    private function publishSurvey(User $admin, string $eventId, string $audience): void
    {
        $this->loginAs($admin);
        $this->client->jsonRequest('POST', '/api/v1/admin/events/'.$eventId.'/surveys', ['title' => 'Une question', 'audience' => $audience, 'opensAt' => self::OPEN, 'closesAt' => self::CLOSE]);
        self::assertResponseStatusCodeSame(201);
        $surveyId = $this->createdId();
        $this->client->jsonRequest('POST', '/api/v1/admin/surveys/'.$surveyId.'/questions', ['kind' => 'text', 'label' => 'Un mot ?', 'required' => true]);
        self::assertResponseStatusCodeSame(201);
        $this->client->request('POST', '/api/v1/admin/surveys/'.$surveyId.'/publish');
        self::assertResponseStatusCodeSame(204);
    }

    private function createdId(): string
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsString($data['id']);

        return $data['id'];
    }
}
