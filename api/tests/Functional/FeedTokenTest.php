<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use App\Sessions\Domain\Session;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class FeedTokenTest extends WebTestCase
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
            $this->entityManager->getClassMetadata(Session::class),
            $this->entityManager->getClassMetadata(Registration::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testFeedTokenRequiresAuth(): void
    {
        $session = $this->createSession('run-auth-1', 'evt-001');

        $this->client->jsonRequest('GET', sprintf('/api/v1/sessions/%s/feed-token', $session->getId()));
        self::assertResponseStatusCodeSame(401);
    }

    public function testFeedTokenForbidsNonRegistrant(): void
    {
        $session = $this->createSession('run-noreg-1', 'evt-001');
        $player = $this->createPlayer('alice@example.org', 'Alice');
        $this->loginAs($player);

        $this->client->jsonRequest('GET', sprintf('/api/v1/sessions/%s/feed-token', $session->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testFeedTokenForbidsNonConfirmedRegistrant(): void
    {
        $session = $this->createSession('run-pending-1', 'evt-001');
        $player = $this->createPlayer('bob@example.org', 'Bob');
        $this->createRegistration($player->getId(), 'evt-001', confirmed: false);
        $this->loginAs($player);

        $this->client->jsonRequest('GET', sprintf('/api/v1/sessions/%s/feed-token', $session->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testFeedTokenAllowsConfirmedRegistrant(): void
    {
        $session = $this->createSession('run-ok-1', 'evt-001');
        $player = $this->createPlayer('charlie@example.org', 'Charlie');
        $this->createRegistration($player->getId(), 'evt-001', confirmed: true);
        $this->loginAs($player);

        $this->client->jsonRequest('GET', sprintf('/api/v1/sessions/%s/feed-token', $session->getId()));
        self::assertResponseStatusCodeSame(200);

        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertIsString($data['token']);
        self::assertIsString($data['hubUrl']);
        self::assertSame('runs/run-ok-1/feed', $data['topic']);
    }

    public function testFeedTokenAllowsAdmin(): void
    {
        $session = $this->createSession('run-admin-1', 'evt-001');
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/sessions/%s/feed-token', $session->getId()));
        self::assertResponseStatusCodeSame(200);

        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertSame('runs/run-admin-1/feed', $data['topic']);
    }

    public function testFeedTokenReturns404ForUnknownSession(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/sessions/nonexistent-run/feed-token');
        self::assertResponseStatusCodeSame(404);
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function createSession(string $id, string $eventId): Session
    {
        $session = Session::create($id, $eventId, new \DateTimeImmutable());
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    private function createAdmin(): User
    {
        $now = new \DateTimeImmutable('2026-05-06T10:00:00+00:00');
        $user = new User(
            bin2hex(random_bytes(16)),
            'admin@example.org',
            'admin@example.org',
            'Admin',
            'test-password-hash',
            ['ROLE_USER', 'ROLE_ADMIN'],
            $now, $now, $now,
        );
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createPlayer(string $email, string $displayName): User
    {
        $now = new \DateTimeImmutable('2026-05-06T10:00:00+00:00');
        $user = new User(
            bin2hex(random_bytes(16)),
            $email,
            strtolower($email),
            $displayName,
            'test-password-hash',
            ['ROLE_USER'],
            $now, $now, $now,
        );
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createRegistration(string $userId, string $eventId, bool $confirmed): Registration
    {
        $now = new \DateTimeImmutable('2026-05-06T10:00:00+00:00');
        $registration = new Registration(
            bin2hex(random_bytes(16)),
            $eventId,
            $userId,
            Registration::STATUS_RESERVED,
            $now,
            $now,
        );
        if ($confirmed) {
            $registration->confirm($now);
        }
        $this->entityManager->persist($registration);
        $this->entityManager->flush();

        return $registration;
    }

    private function loginAs(User $user): void
    {
        $this->client->getCookieJar()->set(
            new Cookie(AuthSessionSigner::COOKIE_NAME, $this->authSessionSigner->sign($user->getId())),
        );
    }

    /** @return array<mixed> */
    private function decodedJsonResponse(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
