<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;

final class AdminEventEditTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testAdminCanFetchOneEvent(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->createEvent('Spring Sync 2027', new \DateTimeImmutable('2027-05-31T10:00:00+00:00'), new \DateTimeImmutable('2027-05-31T22:00:00+00:00'));
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
        $event = $this->createEvent('Spring Sync 2027', new \DateTimeImmutable('2027-05-31T10:00:00+00:00'), new \DateTimeImmutable('2027-05-31T22:00:00+00:00'), capacity: 1);
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
        $event = $this->createEvent('Spring Sync 2027', new \DateTimeImmutable('2027-05-31T10:00:00+00:00'), new \DateTimeImmutable('2027-05-31T22:00:00+00:00'));
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
            ['source' => 'url', 'url' => 'https://cdn.archilan.fr/events/updated-1.webp'],
            ['source' => 'url', 'url' => 'https://cdn.archilan.fr/events/updated-2.webp'],
        ], $stored->getPhotoGallery());
    }

    public function testInvalidPhotoGalleryIsRejectedOnUpdate(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->createEvent('Spring Sync 2027', new \DateTimeImmutable('2027-05-31T10:00:00+00:00'), new \DateTimeImmutable('2027-05-31T22:00:00+00:00'));
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
        $event = $this->createEvent('Spring Sync 2027', new \DateTimeImmutable('2027-05-31T10:00:00+00:00'), new \DateTimeImmutable('2027-05-31T22:00:00+00:00'));
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
        $event = $this->createEvent('Spring Sync 2027', new \DateTimeImmutable('2027-05-31T10:00:00+00:00'), new \DateTimeImmutable('2027-05-31T22:00:00+00:00'));
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
        $event = $this->createEvent('Spring Sync 2027', new \DateTimeImmutable('2027-05-31T10:00:00+00:00'), new \DateTimeImmutable('2027-05-31T22:00:00+00:00'));
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

    public function testStandardCannotEditEvents(): void
    {
        $user = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $event = $this->createEvent('Spring Sync 2027', new \DateTimeImmutable('2027-05-31T10:00:00+00:00'), new \DateTimeImmutable('2027-05-31T22:00:00+00:00'));
        $this->loginAs($user);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s', $event->getId()), $this->validPayload());

        self::assertResponseStatusCodeSame(403);
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
}
