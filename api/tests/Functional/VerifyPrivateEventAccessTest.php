<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Events\Domain\EventPrivateAccessLog;
use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class VerifyPrivateEventAccessTest extends WebTestCase
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

        $event = $this->createEvent(Event::STATUS_DRAFT, isPublic: false, passwordHash: password_hash('secret', PASSWORD_BCRYPT));

        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/verify-private-access', $event->getId()), ['password' => 'secret']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCorrectPasswordGrantsAccess(): void
    {
        $user = $this->createUser('user@example.org');
        $this->loginAs($user);

        $event = $this->createEvent(Event::STATUS_PUBLISHED, isPublic: false, passwordHash: password_hash('the-pass', PASSWORD_BCRYPT));

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

        $event = $this->createEvent(
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

        $event = $this->createEvent(
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

        $event = $this->createEvent(Event::STATUS_PUBLISHED, isPublic: false, passwordHash: null);

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

        $event = $this->createEvent(Event::STATUS_PUBLISHED, isPublic: true, passwordHash: null);

        $this->client->jsonRequest('POST', sprintf('/api/v1/events/%s/verify-private-access', $event->getId()), ['password' => 'any']);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertFalse($data['granted']);
    }

    private function createEvent(
        string $status,
        bool $isPublic,
        ?string $passwordHash,
        \DateTimeImmutable $registrationOpensAt = new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        \DateTimeImmutable $registrationClosesAt = new \DateTimeImmutable('2027-01-01T00:00:00+00:00'),
    ): Event {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = new Event(
            bin2hex(random_bytes(16)),
            'Autumn Sync 2027',
            'Une session Archipelago.',
            $status,
            new \DateTimeImmutable('2027-10-01T10:00:00+00:00'),
            new \DateTimeImmutable('2027-10-01T22:00:00+00:00'),
            'Clermont-Ferrand',
            48,
            $registrationOpensAt,
            $registrationClosesAt,
            $isPublic,
            $passwordHash,
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

    private function createUser(string $email): User
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $user = new User(
            bin2hex(random_bytes(16)),
            $email,
            mb_strtolower($email),
            null,
            'test-password-hash',
            ['ROLE_USER'],
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

    /**
     * @return array<mixed>
     */
    private function decodedJsonResponse(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
