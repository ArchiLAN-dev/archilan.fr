<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Application\EventCapacityReachedMessage;
use App\Events\Domain\Event;
use App\Events\Domain\EventPrivateAccessLog;
use App\GameSelection\Domain\ArchipelagoGame;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class CapacityNotificationTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Event::class),
            $this->entityManager->getClassMetadata(EventPrivateAccessLog::class),
            $this->entityManager->getClassMetadata(Registration::class),
            $this->entityManager->getClassMetadata(ArchipelagoGame::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testNotificationDispatchedWhenLastSeatClaimed(): void
    {
        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $event = $this->makeEvent(capacity: 1);
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/events/'.$event->getId().'/registrations');
        self::assertResponseStatusCodeSame(201);

        $transport = $this->transport();
        $sent = $transport->getSent();
        self::assertCount(1, $sent);

        $envelope = $sent[0];
        $message = $envelope->getMessage();
        self::assertInstanceOf(EventCapacityReachedMessage::class, $message);
        self::assertSame($event->getId(), $message->eventId);
        self::assertSame('Spring Sync 2027', $message->eventTitle);
        self::assertSame(1, $message->capacity);

        $this->entityManager->refresh($event);
        self::assertTrue($event->isCapacityNotificationSent());
    }

    public function testNotificationNotDispatchedWhenSeatsRemain(): void
    {
        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $event = $this->makeEvent(capacity: 10);
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/events/'.$event->getId().'/registrations');
        self::assertResponseStatusCodeSame(201);

        self::assertCount(0, $this->transport()->getSent());
    }

    public function testNotificationNotDispatchedWhenAlreadySent(): void
    {
        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $event = $this->makeEvent(capacity: 1, notificationAlreadySent: true);
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/events/'.$event->getId().'/registrations');
        self::assertResponseStatusCodeSame(201);

        self::assertCount(0, $this->transport()->getSent());
    }

    private function makeEvent(int $capacity = 48, bool $notificationAlreadySent = false): Event
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = $this->createEvent(
            'Spring Sync 2027',
            new \DateTimeImmutable('2027-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-31T22:00:00+00:00'),
            capacity: $capacity,
            published: true,
            registrationOpensAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            registrationClosesAt: new \DateTimeImmutable('2027-05-30T18:00:00+00:00'),
        );
        if ($notificationAlreadySent) {
            $event->markCapacityNotificationSent($now);
            $this->entityManager->flush();
        }

        return $event;
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
