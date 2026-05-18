<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Identity\Domain\User;
use Doctrine\ORM\Tools\SchemaTool;

final class AdminEventDraftTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Event::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAnonymousAndUserCannotManageEvents(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/events');
        self::assertResponseStatusCodeSame(401);

        $user = $this->createUser('lambda@example.org', ['ROLE_USER'], 'User');
        $this->loginAs($user);
        $this->client->jsonRequest('POST', '/api/v1/admin/events', $this->validPayload());
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminSeesEmptyEventList(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/events');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertSame([], $response['data']);
    }

    public function testAdminCreatesDraftAndListsIt(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events', $this->validPayload());

        self::assertResponseStatusCodeSame(201);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame('Spring Sync 2027', $response['data']['title']);
        self::assertSame(Event::STATUS_DRAFT, $response['data']['status']);
        self::assertSame(48, $response['data']['capacity']);
        self::assertSame('https://cdn.archilan.fr/events/spring-sync.webp', $response['data']['coverImageUrl']);
        self::assertSame([
            'https://cdn.archilan.fr/events/spring-sync-1.webp',
            'https://cdn.archilan.fr/events/spring-sync-2.webp',
        ], $response['data']['photoGallery']);
        self::assertFalse($response['data']['isAtCapacity']);
        self::assertSame('public', $response['data']['visibility']);

        $this->client->jsonRequest('GET', '/api/v1/admin/events');
        self::assertResponseIsSuccessful();
        $list = $this->decodedJsonResponse();
        self::assertIsArray($list['data']);
        self::assertCount(1, $list['data']);
        $listedEvent = $list['data'][0];
        self::assertIsArray($listedEvent);
        self::assertSame('Spring Sync 2027', $listedEvent['title']);
        self::assertSame('https://cdn.archilan.fr/events/spring-sync.webp', $listedEvent['coverImageUrl']);
        self::assertSame([
            'https://cdn.archilan.fr/events/spring-sync-1.webp',
            'https://cdn.archilan.fr/events/spring-sync-2.webp',
        ], $listedEvent['photoGallery']);
    }

    public function testRequiredFieldsAndDateRangesAreValidated(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events', [
            'title' => '',
            'description' => '',
            'startsAt' => '2027-05-10T10:00:00+00:00',
            'endsAt' => '2027-05-10T09:00:00+00:00',
            'venue' => '',
            'capacity' => 0,
            'registrationOpensAt' => '2027-05-11T10:00:00+00:00',
            'registrationClosesAt' => '2027-05-10T11:00:00+00:00',
            'isPublic' => true,
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        foreach (['title', 'description', 'venue', 'capacity', 'endsAt', 'registrationClosesAt'] as $field) {
            self::assertArrayHasKey($field, $response['error']['details']);
        }
    }

    public function testRegistrationClosesAtEqualToStartsAtIsAccepted(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);

        $payload = $this->validPayload();
        $payload['registrationClosesAt'] = $payload['startsAt'];

        $this->client->jsonRequest('POST', '/api/v1/admin/events', $payload);

        self::assertResponseStatusCodeSame(201);
    }

    public function testRegistrationOpensAtAfterStartsAtIsRejected(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);

        $payload = $this->validPayload();
        $payload['registrationOpensAt'] = '2027-06-01T10:00:00+00:00';
        $payload['registrationClosesAt'] = '2027-06-02T10:00:00+00:00';

        $this->client->jsonRequest('POST', '/api/v1/admin/events', $payload);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('registrationOpensAt', $response['error']['details']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'title' => 'Spring Sync 2027',
            'description' => 'Une session Archipelago de printemps.',
            'startsAt' => '2027-05-31T10:00:00+00:00',
            'endsAt' => '2027-05-31T22:00:00+00:00',
            'venue' => 'Clermont-Ferrand',
            'capacity' => 48,
            'coverImageUrl' => 'https://cdn.archilan.fr/events/spring-sync.webp',
            'photoGallery' => [
                'https://cdn.archilan.fr/events/spring-sync-1.webp',
                'https://cdn.archilan.fr/events/spring-sync-2.webp',
            ],
            'registrationOpensAt' => '2027-05-01T10:00:00+00:00',
            'registrationClosesAt' => '2027-05-30T18:00:00+00:00',
            'isPublic' => true,
        ];
    }
}
