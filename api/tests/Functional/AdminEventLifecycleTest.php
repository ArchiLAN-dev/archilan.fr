<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;

final class AdminEventLifecycleTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testAdminCanPublishStartCompleteAndUnpublishAnEvent(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->makeEvent(Event::STATUS_DRAFT);
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
        $event = $this->makeEvent(Event::STATUS_DRAFT);
        $this->loginAs($admin);

        $this->transition($event, Event::STATUS_COMPLETED);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('status', $response['error']['details']);
    }

    public function testStandardCannotTransitionEvents(): void
    {
        $user = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $event = $this->makeEvent(Event::STATUS_DRAFT);
        $this->loginAs($user);

        $this->transition($event, Event::STATUS_PUBLISHED);

        self::assertResponseStatusCodeSame(403);
    }

    public function testPublicListOnlyIncludesPublicVisibleLifecycleStatuses(): void
    {
        $draft = $this->makeEvent(Event::STATUS_DRAFT, title: 'Draft Hidden');
        $privatePublished = $this->makeEvent(Event::STATUS_PUBLISHED, isPublic: false, title: 'Private Visible');
        $published = $this->makeEvent(Event::STATUS_PUBLISHED, title: 'Published Visible');
        $inProgress = $this->makeEvent(Event::STATUS_IN_PROGRESS, title: 'In Progress Visible');
        $completed = $this->makeEvent(Event::STATUS_COMPLETED, title: 'Completed Visible');

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
        $draft = $this->makeEvent(Event::STATUS_DRAFT, title: 'Draft Hidden');
        $published = $this->makeEvent(Event::STATUS_PUBLISHED, title: 'Published Visible');

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

    private function makeEvent(string $status, bool $isPublic = true, string $title = 'Spring Sync 2027'): Event
    {
        $now = new \DateTimeImmutable('2026-04-25T10:00:00+00:00');
        $slug = mb_strtolower(str_replace(' ', '-', $title));
        $event = $this->createEvent(
            $title,
            new \DateTimeImmutable('2027-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-31T22:00:00+00:00'),
            capacity: 48,
            isPublic: $isPublic,
            coverImageUrl: 'https://cdn.archilan.fr/events/'.$slug.'.webp',
            photoGallery: [
                'https://cdn.archilan.fr/events/'.$slug.'-1.webp',
                'https://cdn.archilan.fr/events/'.$slug.'-2.webp',
            ],
        );
        $this->transitionEventTo($event, $status, $now);
        $this->entityManager->flush();

        return $event;
    }
}
