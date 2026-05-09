<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Application\EventCapacityReachedMessage;
use App\Events\Domain\Event;
use App\Events\Domain\EventPrivateAccessLog;
use App\GameSelection\Domain\ArchipelagoGame;
use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class CapacityNotificationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private AuthSessionSigner $authSessionSigner;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $authSessionSigner = self::getContainer()->get(AuthSessionSigner::class);
        self::assertInstanceOf(AuthSessionSigner::class, $authSessionSigner);
        $this->authSessionSigner = $authSessionSigner;

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
        $event = $this->createEvent(capacity: 1);
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
        $event = $this->createEvent(capacity: 10);
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/events/'.$event->getId().'/registrations');
        self::assertResponseStatusCodeSame(201);

        self::assertCount(0, $this->transport()->getSent());
    }

    public function testNotificationNotDispatchedWhenAlreadySent(): void
    {
        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $event = $this->createEvent(capacity: 1, notificationAlreadySent: true);
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/events/'.$event->getId().'/registrations');
        self::assertResponseStatusCodeSame(201);

        self::assertCount(0, $this->transport()->getSent());
    }

    private function createEvent(int $capacity = 48, bool $notificationAlreadySent = false): Event
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = new Event(
            bin2hex(random_bytes(16)),
            'Spring Sync 2027',
            'Une session Archipelago.',
            Event::STATUS_PUBLISHED,
            new \DateTimeImmutable('2027-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-31T22:00:00+00:00'),
            'Clermont-Ferrand',
            $capacity,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            new \DateTimeImmutable('2027-05-01T00:00:00+00:00'),
            true,
            null,
            false,
            [],
            null,
            null,
            $now,
            $now,
        );

        if ($notificationAlreadySent) {
            $event->markCapacityNotificationSent($now);
        }

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $event;
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles): User
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $user = new User(
            bin2hex(random_bytes(16)),
            $email,
            mb_strtolower($email),
            null,
            'test-password-hash',
            $roles,
            $now,
            $now,
            $now,
        );

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function loginAs(User $user): void
    {
        $this->client->getCookieJar()->set(
            new Cookie(AuthSessionSigner::COOKIE_NAME, $this->authSessionSigner->sign($user->getId())),
        );
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
