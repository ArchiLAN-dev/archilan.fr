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

final class AdminEventRecapTest extends WebTestCase
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

    public function testAnonymousAndLambdaCannotAttachRecap(): void
    {
        $this->client->jsonRequest('PATCH', '/api/v1/admin/events/nonexistent/recap', [
            'vodUrl' => null,
            'recapPostSlug' => null,
        ]);
        self::assertResponseStatusCodeSame(401);

        $lambda = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($lambda);

        $this->client->jsonRequest('PATCH', '/api/v1/admin/events/nonexistent/recap', [
            'vodUrl' => null,
            'recapPostSlug' => null,
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminGets404ForUnknownEvent(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', '/api/v1/admin/events/nonexistent/recap', [
            'vodUrl' => null,
            'recapPostSlug' => null,
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminCannotAttachRecapToNonCompletedEvent(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $event = $this->createEvent(Event::STATUS_PUBLISHED);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/recap', $event->getId()), [
            'vodUrl' => 'https://www.youtube.com/watch?v=abc123',
            'recapPostSlug' => null,
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('status', $response['error']['details']);
    }

    public function testAdminCanAttachVodUrlToCompletedEvent(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $event = $this->createEvent(Event::STATUS_COMPLETED);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/recap', $event->getId()), [
            'vodUrl' => 'https://www.youtube.com/watch?v=abc123',
            'recapPostSlug' => null,
        ]);

        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s', $event->getId()));
        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame('https://www.youtube.com/watch?v=abc123', $data['vodUrl']);
        self::assertNull($data['recapPostSlug']);
        self::assertTrue($data['hasRecap']);
    }

    public function testAdminCanAttachRecapPostSlugToCompletedEvent(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $event = $this->createEvent(Event::STATUS_COMPLETED);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/recap', $event->getId()), [
            'vodUrl' => null,
            'recapPostSlug' => 'spring-sync-2027-recap',
        ]);

        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s', $event->getId()));
        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertNull($data['vodUrl']);
        self::assertSame('spring-sync-2027-recap', $data['recapPostSlug']);
        self::assertTrue($data['hasRecap']);
    }

    public function testAdminCanAttachBothVodAndRecapSlug(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $event = $this->createEvent(Event::STATUS_COMPLETED);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/recap', $event->getId()), [
            'vodUrl' => 'https://twitch.tv/videos/12345',
            'recapPostSlug' => 'spring-sync-2027-recap',
        ]);

        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s', $event->getId()));
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame('https://twitch.tv/videos/12345', $data['vodUrl']);
        self::assertSame('spring-sync-2027-recap', $data['recapPostSlug']);
        self::assertTrue($data['hasRecap']);
    }

    public function testAdminCanClearRecap(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $event = $this->createEvent(Event::STATUS_COMPLETED);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/recap', $event->getId()), [
            'vodUrl' => 'https://www.youtube.com/watch?v=abc123',
            'recapPostSlug' => 'spring-sync-2027-recap',
        ]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/recap', $event->getId()), [
            'vodUrl' => null,
            'recapPostSlug' => null,
        ]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s', $event->getId()));
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertNull($data['vodUrl']);
        self::assertNull($data['recapPostSlug']);
        self::assertFalse($data['hasRecap']);
    }

    public function testInvalidVodUrlIsRejected(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $event = $this->createEvent(Event::STATUS_COMPLETED);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/recap', $event->getId()), [
            'vodUrl' => 'not-a-valid-url',
            'recapPostSlug' => null,
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('vodUrl', $response['error']['details']);
    }

    public function testInvalidRecapSlugIsRejected(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $event = $this->createEvent(Event::STATUS_COMPLETED);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/recap', $event->getId()), [
            'vodUrl' => null,
            'recapPostSlug' => 'Invalid Slug With Spaces!',
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('recapPostSlug', $response['error']['details']);
    }

    public function testRecapReflectedInAdminEventList(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $event = $this->createEvent(Event::STATUS_COMPLETED);

        $this->client->jsonRequest('GET', '/api/v1/admin/events');
        $list = $this->decodedJsonResponse();
        $listData = $list['data'];
        self::assertIsArray($listData);
        self::assertCount(1, $listData);
        $firstEvent = $listData[0];
        self::assertIsArray($firstEvent);
        self::assertFalse($firstEvent['hasRecap']);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/recap', $event->getId()), [
            'vodUrl' => 'https://www.youtube.com/watch?v=abc123',
            'recapPostSlug' => null,
        ]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', '/api/v1/admin/events');
        $list2 = $this->decodedJsonResponse();
        $listData2 = $list2['data'];
        self::assertIsArray($listData2);
        $updatedEvent = $listData2[0];
        self::assertIsArray($updatedEvent);
        self::assertTrue($updatedEvent['hasRecap']);
        self::assertSame('https://www.youtube.com/watch?v=abc123', $updatedEvent['vodUrl']);
    }

    private function createEvent(string $status): Event
    {
        $now = new \DateTimeImmutable('2026-04-30T10:00:00+00:00');
        $event = new Event(
            bin2hex(random_bytes(16)),
            'Spring Sync 2027',
            'Une session Archipelago de printemps.',
            $status,
            new \DateTimeImmutable('2027-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-31T22:00:00+00:00'),
            'Clermont-Ferrand',
            48,
            new \DateTimeImmutable('2027-05-01T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-30T18:00:00+00:00'),
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

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles): User
    {
        $now = new \DateTimeImmutable('2026-04-30T10:00:00+00:00');
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
