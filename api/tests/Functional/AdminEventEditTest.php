<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class AdminEventEditTest extends WebTestCase
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
            $this->entityManager->getClassMetadata(Registration::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAdminCanFetchOneEvent(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->createEvent();
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s', $event->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame('Spring Sync 2027', $response['data']['title']);
        self::assertNull($response['data']['coverImageUrl']);
        self::assertSame([], $response['data']['photoGallery']);
        self::assertSame(0, $response['data']['confirmedRegistrations']);
        self::assertFalse($response['data']['isAtCapacity']);
    }

    public function testAdminEventPayloadMarksCapacityReached(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->createEvent(capacity: 1);
        $this->createRegistration($event->getId(), 'other-user-id');
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s', $event->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertTrue($response['data']['isAtCapacity']);
    }

    public function testAdminCanUpdateEventDetails(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->createEvent();
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s', $event->getId()), [
            ...$this->validPayload(),
            'title' => 'Spring Sync Updated',
            'capacity' => 64,
            'coverImageUrl' => 'https://cdn.archilan.fr/events/updated.webp',
            'photoGallery' => [
                'https://cdn.archilan.fr/events/updated-1.webp',
                'https://cdn.archilan.fr/events/updated-2.webp',
            ],
            'isPublic' => false,
        ]);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame('Spring Sync Updated', $response['data']['title']);
        self::assertSame(64, $response['data']['capacity']);
        self::assertSame('https://cdn.archilan.fr/events/updated.webp', $response['data']['coverImageUrl']);
        self::assertSame([
            'https://cdn.archilan.fr/events/updated-1.webp',
            'https://cdn.archilan.fr/events/updated-2.webp',
        ], $response['data']['photoGallery']);
        self::assertFalse($response['data']['isPublic']);
        self::assertSame('private', $response['data']['visibility']);

        $this->entityManager->clear();
        $stored = $this->entityManager->find(Event::class, $event->getId());
        self::assertInstanceOf(Event::class, $stored);
        self::assertSame('Spring Sync Updated', $stored->getTitle());
        self::assertSame('https://cdn.archilan.fr/events/updated.webp', $stored->getCoverImageUrl());
        self::assertSame([
            'https://cdn.archilan.fr/events/updated-1.webp',
            'https://cdn.archilan.fr/events/updated-2.webp',
        ], $stored->getPhotoGallery());
    }

    public function testInvalidPhotoGalleryIsRejectedOnUpdate(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->createEvent();
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s', $event->getId()), [
            ...$this->validPayload(),
            'photoGallery' => ['https://cdn.archilan.fr/events/only-one.webp'],
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('photoGallery', $response['error']['details']);
    }

    public function testInvalidCoverImageUrlIsRejectedOnUpdate(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->createEvent();
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s', $event->getId()), [
            ...$this->validPayload(),
            'coverImageUrl' => 'not-a-url',
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('coverImageUrl', $response['error']['details']);
    }

    public function testInvalidDateRangesAreRejectedOnUpdate(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->createEvent();
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s', $event->getId()), [
            ...$this->validPayload(),
            'endsAt' => '2027-05-31T09:00:00+00:00',
            'registrationClosesAt' => '2027-06-01T09:00:00+00:00',
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('endsAt', $response['error']['details']);
        self::assertArrayHasKey('registrationClosesAt', $response['error']['details']);
    }

    public function testCapacityCannotBeLoweredBelowConfirmedRegistrations(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->createEvent();
        for ($i = 0; $i < 3; ++$i) {
            $this->createRegistration($event->getId(), 'user-'.$i);
        }
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s', $event->getId()), [
            ...$this->validPayload(),
            'capacity' => 2,
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('capacity', $response['error']['details']);
    }

    public function testAdminPatchesNonexistentEventReturns404(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', '/api/v1/admin/events/nonexistent-id', $this->validPayload());

        self::assertResponseStatusCodeSame(404);
    }

    public function testLambdaCannotEditEvents(): void
    {
        $lambda = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $event = $this->createEvent();
        $this->loginAs($lambda);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s', $event->getId()), $this->validPayload());

        self::assertResponseStatusCodeSame(403);
    }

    private function createEvent(int $capacity = 48): Event
    {
        $now = new \DateTimeImmutable('2026-04-25T10:00:00+00:00');
        $event = new Event(
            bin2hex(random_bytes(16)),
            'Spring Sync 2027',
            'Une session Archipelago.',
            Event::STATUS_DRAFT,
            new \DateTimeImmutable('2027-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-31T22:00:00+00:00'),
            'Clermont-Ferrand',
            $capacity,
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

    private function createRegistration(string $eventId, string $userId): Registration
    {
        $now = new \DateTimeImmutable('2026-04-25T10:00:00+00:00');
        $registration = new Registration(bin2hex(random_bytes(16)), $eventId, $userId, Registration::STATUS_RESERVED, $now, $now);
        $this->entityManager->persist($registration);
        $this->entityManager->flush();

        return $registration;
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'title' => 'Spring Sync 2027',
            'description' => 'Une session Archipelago.',
            'startsAt' => '2027-05-31T10:00:00+00:00',
            'endsAt' => '2027-05-31T22:00:00+00:00',
            'venue' => 'Clermont-Ferrand',
            'capacity' => 48,
            'registrationOpensAt' => '2027-05-01T10:00:00+00:00',
            'registrationClosesAt' => '2027-05-30T18:00:00+00:00',
            'isPublic' => true,
        ];
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
