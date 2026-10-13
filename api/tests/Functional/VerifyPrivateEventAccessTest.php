<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Entity\EventPrivateAccessLog;

final class VerifyPrivateEventAccessTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testAnonymousGets401(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/events/nonexistent/verify-private-access', ['password' => 'x']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testAuthenticatedGets404ForUnknownEvent(): void
    {
        $user = $this->createUser('user@example.org');
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/events/nonexistent/verify-private-access', ['password' => 'x']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDraftEventReturns404(): void
    {
        $user = $this->createUser('user@example.org');
        $this->loginAs($user);

        $event = $this->makeEvent(Event::STATUS_DRAFT, isPublic: false, passwordHash: password_hash('secret', PASSWORD_BCRYPT));

        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/verify-private-access', $event->getId()), ['password' => 'secret']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCorrectPasswordGrantsAccess(): void
    {
        $user = $this->createUser('user@example.org');
        $this->loginAs($user);

        $event = $this->makeEvent(Event::STATUS_PUBLISHED, isPublic: false, passwordHash: password_hash('the-pass', PASSWORD_BCRYPT));

        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/verify-private-access', $event->getId()), ['password' => 'the-pass']);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertTrue($data['granted']);

        $this->entityManager->clear();
        $logs = $this->entityManager->getRepository(EventPrivateAccessLog::class)->findAll();
        self::assertCount(1, $logs);
        $log = $logs[0];
        self::assertInstanceOf(EventPrivateAccessLog::class, $log);
        self::assertTrue($log->isGranted());
        self::assertSame($event->getId(), $log->getEventId());
        self::assertSame($user->getId(), $log->getUserId());
    }

    public function testWrongPasswordDeniesAccess(): void
    {
        $user = $this->createUser('user@example.org');
        $this->loginAs($user);

        $event = $this->makeEvent(
            Event::STATUS_PUBLISHED,
            isPublic: false,
            passwordHash: password_hash('the-pass', PASSWORD_BCRYPT),
            registrationOpensAt: new \DateTimeImmutable('2027-05-01T00:00:00+00:00'),
            registrationClosesAt: new \DateTimeImmutable('2027-09-30T18:00:00+00:00'),
        );

        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/verify-private-access', $event->getId()), ['password' => 'wrong']);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertFalse($data['granted']);

        $this->entityManager->clear();
        $logs = $this->entityManager->getRepository(EventPrivateAccessLog::class)->findAll();
        self::assertCount(1, $logs);
        $log = $logs[0];
        self::assertInstanceOf(EventPrivateAccessLog::class, $log);
        self::assertFalse($log->isGranted());
    }

    public function testCorrectPasswordDoesNotGrantAccessBeforeRegistrationOpens(): void
    {
        $user = $this->createUser('user@example.org');
        $this->loginAs($user);

        $event = $this->makeEvent(
            Event::STATUS_PUBLISHED,
            isPublic: false,
            passwordHash: password_hash('the-pass', PASSWORD_BCRYPT),
            registrationOpensAt: new \DateTimeImmutable('2027-05-01T00:00:00+00:00'),
            registrationClosesAt: new \DateTimeImmutable('2027-09-30T18:00:00+00:00'),
        );

        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/verify-private-access', $event->getId()), ['password' => 'the-pass']);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertFalse($data['granted']);

        $this->entityManager->clear();
        $logs = $this->entityManager->getRepository(EventPrivateAccessLog::class)->findAll();
        self::assertCount(1, $logs);
        $log = $logs[0];
        self::assertInstanceOf(EventPrivateAccessLog::class, $log);
        self::assertFalse($log->isGranted());
    }

    public function testPrivateEventWithoutPasswordReturnsFalse(): void
    {
        $user = $this->createUser('user@example.org');
        $this->loginAs($user);

        $event = $this->makeEvent(Event::STATUS_PUBLISHED, isPublic: false, passwordHash: null);

        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/verify-private-access', $event->getId()), ['password' => 'any']);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertFalse($data['granted']);

        $this->entityManager->clear();
        $logs = $this->entityManager->getRepository(EventPrivateAccessLog::class)->findAll();
        self::assertCount(0, $logs);
    }

    public function testPublicEventReturnsFalse(): void
    {
        $user = $this->createUser('user@example.org');
        $this->loginAs($user);

        $event = $this->makeEvent(Event::STATUS_PUBLISHED, isPublic: true, passwordHash: null);

        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/verify-private-access', $event->getId()), ['password' => 'any']);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertFalse($data['granted']);
    }

    /** Story 4.24: ten refused codes in the hour block the member on that event, without checking or logging more. */
    public function testTenRefusedCodesInAnHourBlockTheMemberOnThatEvent(): void
    {
        $user = $this->createUser('user@example.org');
        $other = $this->createUser('other@example.org');
        $event = $this->makeEvent(Event::STATUS_PUBLISHED, isPublic: false, passwordHash: password_hash('the-pass', PASSWORD_BCRYPT));
        $otherEvent = $this->makeEvent(Event::STATUS_PUBLISHED, isPublic: false, passwordHash: password_hash('the-pass', PASSWORD_BCRYPT));
        $this->seedRefusedAttempts($event, $user->getId(), new \DateTimeImmutable('-10 minutes'));

        $this->loginAs($user);
        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/verify-private-access', $event->getId()), ['password' => 'the-pass']);
        self::assertResponseStatusCodeSame(429);
        $error = $this->decodedJsonResponse()['error'];
        self::assertIsArray($error);
        self::assertSame('too_many_attempts', $error['code']);
        $this->entityManager->clear();
        self::assertCount(10, $this->entityManager->getRepository(EventPrivateAccessLog::class)->findAll());

        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/verify-private-access', $otherEvent->getId()), ['password' => 'the-pass']);
        self::assertResponseIsSuccessful();

        $this->loginAs($other);
        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/verify-private-access', $event->getId()), ['password' => 'the-pass']);
        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertTrue($data['granted']);
    }

    public function testRefusedCodesOlderThanAnHourNoLongerBlock(): void
    {
        $user = $this->createUser('user@example.org');
        $event = $this->makeEvent(Event::STATUS_PUBLISHED, isPublic: false, passwordHash: password_hash('the-pass', PASSWORD_BCRYPT));
        $this->seedRefusedAttempts($event, $user->getId(), new \DateTimeImmutable('-61 minutes'));

        $this->loginAs($user);
        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/verify-private-access', $event->getId()), ['password' => 'the-pass']);

        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertTrue($data['granted']);
    }

    private function seedRefusedAttempts(Event $event, string $userId, \DateTimeImmutable $at): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->entityManager->persist(new EventPrivateAccessLog(bin2hex(random_bytes(16)), $event->getId(), $userId, false, $at));
        }
        $this->entityManager->flush();
    }

    private function makeEvent(
        string $status,
        bool $isPublic,
        ?string $passwordHash,
        \DateTimeImmutable $registrationOpensAt = new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        \DateTimeImmutable $registrationClosesAt = new \DateTimeImmutable('2027-01-01T00:00:00+00:00'),
    ): Event {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = $this->createEvent(
            'Autumn Sync 2027',
            new \DateTimeImmutable('2027-10-01T10:00:00+00:00'),
            new \DateTimeImmutable('2027-10-01T22:00:00+00:00'),
            capacity: 48,
            isPublic: $isPublic,
            registrationOpensAt: $registrationOpensAt,
            registrationClosesAt: $registrationClosesAt,
        );
        $this->transitionEventTo($event, $status, $now);
        if (null !== $passwordHash) {
            $event->configurePrivateAccessPassword($passwordHash, $now);
        }
        $this->entityManager->flush();

        return $event;
    }
}
