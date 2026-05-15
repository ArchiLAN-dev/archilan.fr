<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use Doctrine\ORM\Tools\SchemaTool;

final class RegistrationEligibilityTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Event::class),
            $this->entityManager->getClassMetadata(Registration::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAnonymousGets401(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/events/nonexistent/registration-eligibility');
        self::assertResponseStatusCodeSame(401);
    }

    public function testAuthenticatedUserGets404ForUnknownEvent(): void
    {
        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($user);

        $this->client->jsonRequest('GET', '/api/v1/events/nonexistent/registration-eligibility');
        self::assertResponseStatusCodeSame(404);
    }

    public function testPrivateEventIsNotEligible(): void
    {
        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($user);

        $event = $this->makeEvent(
            Event::STATUS_PUBLISHED,
            isPublic: false,
            registrationOpensAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            registrationClosesAt: new \DateTimeImmutable('2027-01-01T00:00:00+00:00'),
        );

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/registration-eligibility', $event->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertFalse($data['eligible']);
        self::assertSame('private_event', $data['reason']);
        self::assertNull($data['opensAt']);
    }

    public function testPrivateEventStillReportsRegistrationWindowBeforeAccessType(): void
    {
        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($user);

        $event = $this->makeEvent(
            Event::STATUS_PUBLISHED,
            isPublic: false,
            registrationOpensAt: new \DateTimeImmutable('2028-01-01T00:00:00+00:00'),
            registrationClosesAt: new \DateTimeImmutable('2028-06-01T00:00:00+00:00'),
        );

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/registration-eligibility', $event->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertFalse($data['eligible']);
        self::assertSame('registration_not_open_yet', $data['reason']);
    }

    public function testCompletedEventIsNotEligible(): void
    {
        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($user);

        $event = $this->makeEvent(Event::STATUS_COMPLETED);

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/registration-eligibility', $event->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertFalse($data['eligible']);
        self::assertSame('event_completed', $data['reason']);
    }

    public function testInProgressEventIsNotEligible(): void
    {
        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($user);

        $event = $this->makeEvent(
            Event::STATUS_IN_PROGRESS,
            registrationOpensAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            registrationClosesAt: new \DateTimeImmutable('2027-01-01T00:00:00+00:00'),
        );

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/registration-eligibility', $event->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertFalse($data['eligible']);
        self::assertSame('event_in_progress', $data['reason']);
    }

    public function testRegistrationNotOpenYet(): void
    {
        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($user);

        $event = $this->makeEvent(
            Event::STATUS_PUBLISHED,
            registrationOpensAt: new \DateTimeImmutable('2028-01-01T00:00:00+00:00'),
            registrationClosesAt: new \DateTimeImmutable('2028-06-01T00:00:00+00:00'),
        );

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/registration-eligibility', $event->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertFalse($data['eligible']);
        self::assertSame('registration_not_open_yet', $data['reason']);
        self::assertIsString($data['opensAt']);
        self::assertStringContainsString('2028-01-01', $data['opensAt']);
    }

    public function testRegistrationClosed(): void
    {
        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($user);

        $event = $this->makeEvent(
            Event::STATUS_PUBLISHED,
            registrationOpensAt: new \DateTimeImmutable('2024-01-01T00:00:00+00:00'),
            registrationClosesAt: new \DateTimeImmutable('2024-06-01T00:00:00+00:00'),
        );

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/registration-eligibility', $event->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertFalse($data['eligible']);
        self::assertSame('registration_closed', $data['reason']);
        self::assertNull($data['opensAt']);
    }

    public function testCapacityFull(): void
    {
        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($user);

        $event = $this->makeEvent(
            Event::STATUS_PUBLISHED,
            capacity: 1,
            registrationOpensAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            registrationClosesAt: new \DateTimeImmutable('2027-01-01T00:00:00+00:00'),
        );
        $this->createRegistration($event->getId(), 'other-user-id');

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/registration-eligibility', $event->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertFalse($data['eligible']);
        self::assertSame('capacity_full', $data['reason']);
    }

    public function testEligiblePublicOpenEvent(): void
    {
        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($user);

        $event = $this->makeEvent(
            Event::STATUS_PUBLISHED,
            registrationOpensAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            registrationClosesAt: new \DateTimeImmutable('2027-01-01T00:00:00+00:00'),
        );

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/registration-eligibility', $event->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertTrue($data['eligible']);
        self::assertNull($data['reason']);
        self::assertNull($data['opensAt']);
        $eventData = $data['event'];
        self::assertIsArray($eventData);
        self::assertSame($event->getId(), $eventData['id']);
        self::assertSame('Spring Sync 2027', $eventData['title']);
    }

    public function testDraftEventIsNotReachable(): void
    {
        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($user);

        $event = $this->makeEvent(Event::STATUS_DRAFT);

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/registration-eligibility', $event->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    private function makeEvent(
        string $status,
        bool $isPublic = true,
        int $capacity = 48,
        \DateTimeImmutable $registrationOpensAt = new \DateTimeImmutable('2027-05-01T00:00:00+00:00'),
        \DateTimeImmutable $registrationClosesAt = new \DateTimeImmutable('2027-05-30T18:00:00+00:00'),
    ): Event {
        $now = new \DateTimeImmutable('2026-04-30T10:00:00+00:00');
        $event = $this->createEvent(
            'Spring Sync 2027',
            new \DateTimeImmutable('2027-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-31T22:00:00+00:00'),
            capacity: $capacity,
            isPublic: $isPublic,
            registrationOpensAt: $registrationOpensAt,
            registrationClosesAt: $registrationClosesAt,
        );
        $this->transitionEventTo($event, $status, $now);
        $this->entityManager->flush();

        return $event;
    }
}
