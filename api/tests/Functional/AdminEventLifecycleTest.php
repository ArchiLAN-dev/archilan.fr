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

final class AdminEventLifecycleTest extends WebTestCase
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

    public function testAdminCanPublishStartCompleteAndUnpublishAnEvent(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->createEvent(Event::STATUS_DRAFT);
        $this->loginAs($admin);

        $this->transition($event, Event::STATUS_PUBLISHED);
        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame(Event::STATUS_PUBLISHED, $response['data']['status']);

        $this->transition($event, Event::STATUS_IN_PROGRESS);
        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame(Event::STATUS_IN_PROGRESS, $response['data']['status']);

        $this->transition($event, Event::STATUS_COMPLETED);
        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame(Event::STATUS_COMPLETED, $response['data']['status']);

        $this->transition($event, Event::STATUS_PUBLISHED);
        self::assertResponseIsSuccessful();
        $this->transition($event, Event::STATUS_DRAFT);
        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame(Event::STATUS_DRAFT, $response['data']['status']);
    }

    public function testInvalidLifecycleTransitionIsRejected(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->createEvent(Event::STATUS_DRAFT);
        $this->loginAs($admin);

        $this->transition($event, Event::STATUS_COMPLETED);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('status', $response['error']['details']);
    }

    public function testLambdaCannotTransitionEvents(): void
    {
        $lambda = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $event = $this->createEvent(Event::STATUS_DRAFT);
        $this->loginAs($lambda);

        $this->transition($event, Event::STATUS_PUBLISHED);

        self::assertResponseStatusCodeSame(403);
    }

    public function testPublicListOnlyIncludesPublicVisibleLifecycleStatuses(): void
    {
        $draft = $this->createEvent(Event::STATUS_DRAFT, title: 'Draft Hidden');
        $privatePublished = $this->createEvent(Event::STATUS_PUBLISHED, isPublic: false, title: 'Private Visible');
        $published = $this->createEvent(Event::STATUS_PUBLISHED, title: 'Published Visible');
        $inProgress = $this->createEvent(Event::STATUS_IN_PROGRESS, title: 'In Progress Visible');
        $completed = $this->createEvent(Event::STATUS_COMPLETED, title: 'Completed Visible');

        $this->client->jsonRequest('GET', '/api/v1/events');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        $ids = array_column($response['data'], 'id');
        self::assertNotContains($draft->getId(), $ids);
        self::assertContains($privatePublished->getId(), $ids);
        self::assertContains($published->getId(), $ids);
        self::assertContains($inProgress->getId(), $ids);
        self::assertContains($completed->getId(), $ids);
    }

    public function testPublicShowOnlyExposesPublicVisibleEvents(): void
    {
        $draft = $this->createEvent(Event::STATUS_DRAFT, title: 'Draft Hidden');
        $published = $this->createEvent(Event::STATUS_PUBLISHED, title: 'Published Visible');

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s', $draft->getId()));
        self::assertResponseStatusCodeSame(404);

        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s', $published->getId()));
        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame('Published Visible', $response['data']['title']);
        self::assertSame('https://cdn.archilan.fr/events/published-visible.webp', $response['data']['coverImageUrl']);
        self::assertSame([
            'https://cdn.archilan.fr/events/published-visible-1.webp',
            'https://cdn.archilan.fr/events/published-visible-2.webp',
        ], $response['data']['photoGallery']);
    }

    private function transition(Event $event, string $status): void
    {
        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/status', $event->getId()), ['status' => $status]);
    }

    private function createEvent(string $status, bool $isPublic = true, string $title = 'Spring Sync 2027'): Event
    {
        $now = new \DateTimeImmutable('2026-04-25T10:00:00+00:00');
        $event = new Event(
            bin2hex(random_bytes(16)),
            $title,
            'Une session Archipelago.',
            $status,
            new \DateTimeImmutable('2027-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-31T22:00:00+00:00'),
            'Clermont-Ferrand',
            48,
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
            coverImageUrl: 'https://cdn.archilan.fr/events/'.mb_strtolower(str_replace(' ', '-', $title)).'.webp',
            photoGallery: [
                'https://cdn.archilan.fr/events/'.mb_strtolower(str_replace(' ', '-', $title)).'-1.webp',
                'https://cdn.archilan.fr/events/'.mb_strtolower(str_replace(' ', '-', $title)).'-2.webp',
            ],
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
