<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Events\Domain\EventPrivateAccessLog;
use App\Identity\Domain\User;
use Doctrine\ORM\Tools\SchemaTool;

final class VerifyPrivateEventAccessTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Event::class),
            $this->entityManager->getClassMetadata(EventPrivateAccessLog::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
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
