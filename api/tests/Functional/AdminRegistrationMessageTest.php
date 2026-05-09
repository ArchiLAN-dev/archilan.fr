<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Events\Domain\EventPrivateAccessLog;
use App\GameSelection\Domain\ArchipelagoGame;
use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use App\Registrations\Domain\RegistrationAdminMessage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class AdminRegistrationMessageTest extends WebTestCase
{
    use MailerAssertionsTrait;

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
            $this->entityManager->getClassMetadata(RegistrationAdminMessage::class),
            $this->entityManager->getClassMetadata(ArchipelagoGame::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAdminCanSendMessageToRegistrant(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('participant@example.org', ['ROLE_USER']);
        $event = $this->createEvent();
        $registration = $this->createRegistration($event->getId(), $participant->getId());
        $this->loginAs($admin);

        $this->client->jsonRequest(
            'POST',
            '/api/v1/admin/events/'.$event->getId().'/registrations/'.$registration->getId().'/messages',
            ['subject' => 'Information importante', 'body' => 'Veuillez vérifier vos options de jeu.'],
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        $data = $payload['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('sent', $data['outcome']);
        self::assertIsString($data['sentAt']);

        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        self::assertNotNull($email);
        $this->assertEmailAddressContains($email, 'to', 'participant@example.org');
        $this->assertEmailSubjectContains($email, 'Information importante');
        $this->assertEmailTextBodyContains($email, 'Veuillez vérifier vos options de jeu.');
        $history = $this->entityManager->getRepository(RegistrationAdminMessage::class)->findOneBy([
            'registrationId' => $registration->getId(),
        ]);
        self::assertInstanceOf(RegistrationAdminMessage::class, $history);
        self::assertNotSame('', $history->getId());
        self::assertSame($event->getId(), $history->getEventId());
        self::assertSame($admin->getId(), $history->getAdminId());
        self::assertSame('Information importante', $history->getSubject());
    }

    public function testReturns422WhenSubjectMissing(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('participant@example.org', ['ROLE_USER']);
        $event = $this->createEvent();
        $registration = $this->createRegistration($event->getId(), $participant->getId());
        $this->loginAs($admin);

        $this->client->jsonRequest(
            'POST',
            '/api/v1/admin/events/'.$event->getId().'/registrations/'.$registration->getId().'/messages',
            ['subject' => '', 'body' => 'Some body'],
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testReturns422WhenBodyMissing(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('participant@example.org', ['ROLE_USER']);
        $event = $this->createEvent();
        $registration = $this->createRegistration($event->getId(), $participant->getId());
        $this->loginAs($admin);

        $this->client->jsonRequest(
            'POST',
            '/api/v1/admin/events/'.$event->getId().'/registrations/'.$registration->getId().'/messages',
            ['subject' => 'Subject', 'body' => ''],
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testReturns404WhenRegistrationNotFound(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->createEvent();
        $this->loginAs($admin);

        $this->client->jsonRequest(
            'POST',
            '/api/v1/admin/events/'.$event->getId().'/registrations/nonexistent/messages',
            ['subject' => 'Subject', 'body' => 'Body'],
        );

        self::assertResponseStatusCodeSame(404);
    }

    private function createEvent(): Event
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
            48,
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

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $event;
    }

    private function createRegistration(string $eventId, string $userId): Registration
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $registration = new Registration(
            bin2hex(random_bytes(16)),
            $eventId,
            $userId,
            Registration::STATUS_RESERVED,
            $now,
            $now,
        );

        $this->entityManager->persist($registration);
        $this->entityManager->flush();

        return $registration;
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
}
