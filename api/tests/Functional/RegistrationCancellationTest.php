<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Registrations\Domain\Registration;

final class RegistrationCancellationTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testAnonymousGets401OnDelete(): void
    {
        $this->client->jsonRequest('DELETE', '/api/v1/registrations/nonexistent');
        self::assertResponseStatusCodeSame(401);
    }

    public function testUnknownRegistrationReturns404(): void
    {
        $user = $this->createUser('user@example.org');
        $this->loginAs($user);

        $this->client->jsonRequest('DELETE', '/api/v1/registrations/nonexistent');
        self::assertResponseStatusCodeSame(404);
    }

    public function testRegistrationOwnedByOtherUserReturns404(): void
    {
        $owner = $this->createUser('owner@example.org');
        $other = $this->createUser('other@example.org');
        $event = $this->makeEvent(Event::STATUS_PUBLISHED);
        $registration = $this->createRegistration($event->getId(), $owner->getId());

        $this->loginAs($other);
        $this->client->jsonRequest('DELETE', sprintf('/api/v1/registrations/%s', $registration->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testAlreadyCancelledRegistrationReturns404(): void
    {
        $user = $this->createUser('user@example.org');
        $event = $this->makeEvent(Event::STATUS_PUBLISHED);
        $registration = $this->createRegistration($event->getId(), $user->getId(), Registration::STATUS_CANCELLED);

        $this->loginAs($user);
        $this->client->jsonRequest('DELETE', sprintf('/api/v1/registrations/%s', $registration->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testCancellationBlockedWhenEventInProgress(): void
    {
        $user = $this->createUser('user@example.org');
        $event = $this->makeEvent(Event::STATUS_IN_PROGRESS);
        $registration = $this->createRegistration($event->getId(), $user->getId());

        $this->loginAs($user);
        $this->client->jsonRequest('DELETE', sprintf('/api/v1/registrations/%s', $registration->getId()));
        self::assertResponseStatusCodeSame(422);

        $response = $this->decodedJsonResponse();
        $error = $response['error'];
        self::assertIsArray($error);
        self::assertSame('cancellation_not_allowed', $error['code']);
    }

    public function testCancellationBlockedWhenEventCompleted(): void
    {
        $user = $this->createUser('user@example.org');
        $event = $this->makeEvent(Event::STATUS_COMPLETED);
        $registration = $this->createRegistration($event->getId(), $user->getId());

        $this->loginAs($user);
        $this->client->jsonRequest('DELETE', sprintf('/api/v1/registrations/%s', $registration->getId()));
        self::assertResponseStatusCodeSame(422);

        $response = $this->decodedJsonResponse();
        $error = $response['error'];
        self::assertIsArray($error);
        self::assertSame('cancellation_not_allowed', $error['code']);
    }

    public function testSuccessfulCancellationMarksCancelledAndReleasesSeat(): void
    {
        $user = $this->createUser('user@example.org');
        $event = $this->makeEvent(Event::STATUS_PUBLISHED);
        $registration = $this->createRegistration($event->getId(), $user->getId());

        $this->loginAs($user);
        $this->client->jsonRequest('DELETE', sprintf('/api/v1/registrations/%s', $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame('cancelled', $data['outcome']);

        $this->entityManager->clear();

        $refreshedReg = $this->entityManager->find(Registration::class, $registration->getId());
        self::assertInstanceOf(Registration::class, $refreshedReg);
        self::assertSame(Registration::STATUS_CANCELLED, $refreshedReg->getStatus());

        $refreshedReg2 = $this->entityManager->find(Registration::class, $registration->getId());
        self::assertInstanceOf(Registration::class, $refreshedReg2);
        self::assertSame(Registration::STATUS_CANCELLED, $refreshedReg2->getStatus());
    }

    private function makeEvent(string $status): Event
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = $this->createEvent(
            'Spring Sync 2027',
            new \DateTimeImmutable('2027-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-31T22:00:00+00:00'),
            capacity: 48,
        );
        $this->transitionEventTo($event, $status, $now);
        $this->entityManager->flush();

        return $event;
    }
}
