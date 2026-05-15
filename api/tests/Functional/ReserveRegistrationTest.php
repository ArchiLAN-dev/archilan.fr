<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Identity\Domain\User;
use App\Registrations\Application\ReserveRegistration;
use App\Registrations\Domain\Registration;
use Doctrine\ORM\Tools\SchemaTool;

final class ReserveRegistrationTest extends FunctionalTestCase
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
        $this->client->jsonRequest('POST', '/api/v1/events/nonexistent/registrations');
        self::assertResponseStatusCodeSame(401);
    }

    public function testAuthenticatedGets404ForUnknownEvent(): void
    {
        $user = $this->createUser('user@example.org');
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/events/nonexistent/registrations');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDraftEventReturns404(): void
    {
        $user = $this->createUser('user@example.org');
        $this->loginAs($user);

        $event = $this->makeEvent(Event::STATUS_DRAFT);

        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/registrations', $event->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testCompletedEventIsNotEligible(): void
    {
        $user = $this->createUser('user@example.org');
        $this->loginAs($user);

        $event = $this->makeEvent(Event::STATUS_COMPLETED);

        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/registrations', $event->getId()));
        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        $error = $response['error'];
        self::assertIsArray($error);
        self::assertSame('not_eligible', $error['code']);
    }

    public function testPrivateEventIsNotEligible(): void
    {
        $user = $this->createUser('user@example.org');
        $this->loginAs($user);

        $event = $this->makeEvent(Event::STATUS_PUBLISHED, isPublic: false);

        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/registrations', $event->getId()));
        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        $error = $response['error'];
        self::assertIsArray($error);
        self::assertSame('not_eligible', $error['code']);
    }

    public function testOpenPublicEventReservesASeat(): void
    {
        $user = $this->createUser('user@example.org');
        $this->loginAs($user);

        $event = $this->makeEvent(Event::STATUS_PUBLISHED);

        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/registrations', $event->getId()));
        self::assertResponseStatusCodeSame(201);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame('reserved', $data['outcome']);
        self::assertIsString($data['registrationId']);

        $this->entityManager->clear();
        $registrations = $this->entityManager->getRepository(Registration::class)->findAll();
        self::assertCount(1, $registrations);
        $registration = $registrations[0];
        self::assertInstanceOf(Registration::class, $registration);
        self::assertSame(Registration::STATUS_RESERVED, $registration->getStatus());
        self::assertSame($event->getId(), $registration->getEventId());
        self::assertSame($user->getId(), $registration->getUserId());

        self::assertCount(1, $this->entityManager->getRepository(Registration::class)->findAll());
    }

    public function testSecondReservationForSameUserIsIdempotent(): void
    {
        $user = $this->createUser('user@example.org');
        $this->loginAs($user);

        $event = $this->makeEvent(Event::STATUS_PUBLISHED);

        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/registrations', $event->getId()));
        self::assertResponseStatusCodeSame(201);
        $firstResponse = $this->decodedJsonResponse();
        $firstData = $firstResponse['data'];
        self::assertIsArray($firstData);
        $firstId = $firstData['registrationId'];
        self::assertIsString($firstId);

        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/registrations', $event->getId()));
        self::assertResponseStatusCodeSame(200);
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame('already_registered', $data['outcome']);
        self::assertSame($firstId, $data['registrationId']);

        $this->entityManager->clear();
        $registrations = $this->entityManager->getRepository(Registration::class)->findAll();
        self::assertCount(1, $registrations);
    }

    public function testFullEventReturns409(): void
    {
        $user = $this->createUser('user@example.org');
        $this->loginAs($user);

        $event = $this->makeEvent(Event::STATUS_PUBLISHED, capacity: 1);
        $this->createRegistration($event->getId(), 'other-user-id');

        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/registrations', $event->getId()));
        self::assertResponseStatusCodeSame(409);
        $response = $this->decodedJsonResponse();
        $error = $response['error'];
        self::assertIsArray($error);
        self::assertSame('capacity_full', $error['code']);
    }

    public function testReservationUsesFreshLockedCapacityFromRegistrationsTable(): void
    {
        $user = $this->createUser('user@example.org');
        $event = $this->makeEvent(Event::STATUS_PUBLISHED, capacity: 1);

        $this->createRegistration($event->getId(), 'another-user-id');

        $reserveRegistration = self::getContainer()->get(ReserveRegistration::class);
        self::assertInstanceOf(ReserveRegistration::class, $reserveRegistration);

        $result = $reserveRegistration->reserve($event->getId(), $user->getId());

        self::assertSame(['outcome' => 'capacity_full'], $result);

        $this->entityManager->clear();
        $registrations = $this->entityManager->getRepository(Registration::class)->findAll();
        self::assertCount(1, $registrations);
    }

    private function makeEvent(
        string $status,
        bool $isPublic = true,
        int $capacity = 48,
    ): Event {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = $this->createEvent(
            'Spring Sync 2027',
            new \DateTimeImmutable('2027-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-31T22:00:00+00:00'),
            capacity: $capacity,
            isPublic: $isPublic,
            registrationOpensAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            registrationClosesAt: new \DateTimeImmutable('2027-05-30T18:00:00+00:00'),
        );
        $this->transitionEventTo($event, $status, $now);
        $this->entityManager->flush();

        return $event;
    }
}
