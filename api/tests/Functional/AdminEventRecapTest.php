<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Identity\Domain\User;
use Doctrine\ORM\Tools\SchemaTool;

final class AdminEventRecapTest extends FunctionalTestCase
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

        $event = $this->makeEvent(Event::STATUS_PUBLISHED);

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

        $event = $this->makeEvent(Event::STATUS_COMPLETED);

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

        $event = $this->makeEvent(Event::STATUS_COMPLETED);

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

        $event = $this->makeEvent(Event::STATUS_COMPLETED);

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

        $event = $this->makeEvent(Event::STATUS_COMPLETED);

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

        $event = $this->makeEvent(Event::STATUS_COMPLETED);

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

        $event = $this->makeEvent(Event::STATUS_COMPLETED);

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

        $event = $this->makeEvent(Event::STATUS_COMPLETED);

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

    private function makeEvent(string $status): Event
    {
        $now = new \DateTimeImmutable('2026-04-30T10:00:00+00:00');
        $event = $this->createEvent(
            'Spring Sync 2027',
            new \DateTimeImmutable('2027-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-31T22:00:00+00:00'),
            capacity: 48,
        );
        $this->transitionEventTo($event, $status, $now);
        $this->entityManager->flush();

        return $event;
    }
}
