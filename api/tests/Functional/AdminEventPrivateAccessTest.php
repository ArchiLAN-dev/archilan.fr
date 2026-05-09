<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class AdminEventPrivateAccessTest extends WebTestCase
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
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAdminCanConfigurePrivateEventPasswordWithoutPlainTextExposure(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->createEvent(isPublic: false);
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/private-access', $event->getId()), [
            'password' => 'private-access-passphrase',
        ]);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertFalse($response['data']['isPublic']);
        self::assertTrue($response['data']['hasPrivateAccessPassword']);
        self::assertArrayNotHasKey('password', $response['data']);
        self::assertArrayNotHasKey('privateAccessPasswordHash', $response['data']);
        self::assertStringNotContainsString('private-access-passphrase', $this->client->getResponse()->getContent() ?: '');
    }

    public function testPasswordCanOnlyBeConfiguredForPrivateEvents(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->createEvent(isPublic: true);
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/private-access', $event->getId()), [
            'password' => 'private-access-passphrase',
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('visibility', $response['error']['details']);
    }

    public function testPasswordValidationRejectsShortPassword(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->createEvent(isPublic: false);
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/private-access', $event->getId()), [
            'password' => 'short',
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('password', $response['error']['details']);
    }

    public function testLambdaCannotConfigurePrivateAccess(): void
    {
        $lambda = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $event = $this->createEvent(isPublic: false);
        $this->loginAs($lambda);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/private-access', $event->getId()), [
            'password' => 'private-access-passphrase',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testPublicListMarksPrivateProtectedEventWithoutExposingPassword(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->createEvent(isPublic: false);
        $this->loginAs($admin);
        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/private-access', $event->getId()), [
            'password' => 'private-access-passphrase',
        ]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', '/api/v1/events');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertCount(1, $response['data']);
        self::assertIsArray($response['data'][0]);
        self::assertFalse($response['data'][0]['isPublic']);
        self::assertTrue($response['data'][0]['hasPrivateAccessPassword']);
        self::assertArrayNotHasKey('password', $response['data'][0]);
        self::assertArrayNotHasKey('privateAccessPasswordHash', $response['data'][0]);
    }

    private function createEvent(bool $isPublic): Event
    {
        $now = new \DateTimeImmutable('2026-04-25T10:00:00+00:00');
        $event = new Event(
            bin2hex(random_bytes(16)),
            'Private Seed 2027',
            'Une session Archipelago privée.',
            Event::STATUS_PUBLISHED,
            new \DateTimeImmutable('2027-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-31T22:00:00+00:00'),
            'Discord ArchiLAN',
            24,
            new \DateTimeImmutable('2027-05-01T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-30T18:00:00+00:00'),
            $isPublic,
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

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles): User
    {
        $now = new \DateTimeImmutable('2026-04-25T10:00:00+00:00');
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
