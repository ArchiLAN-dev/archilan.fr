<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Content\Domain\Entity\Post;
use App\Events\Domain\Entity\Event;
use App\Shared\Application\Message\RevalidatePublicPagesJob;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Story 34.10 AC 4: a write that changes a public page queues the revalidation of its tags, after the commit;
 * a refused write queues nothing.
 */
final class PublicPagesRevalidationTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
    }

    public function testPublishingAnEventRevalidatesItsPageAndTheLists(): void
    {
        $event = $this->draftEvent();

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/status', $event->getId()), ['status' => Event::STATUS_PUBLISHED]);

        self::assertResponseIsSuccessful();
        self::assertSame([['events', 'event:'.$event->getId()]], $this->queuedTags());
    }

    public function testARefusedPrivateAccessRevalidatesNothing(): void
    {
        $event = $this->draftEvent();

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/private-access', $event->getId()), ['password' => 'un-mot-de-passe']);

        self::assertResponseStatusCodeSame(422, 'a public event takes no password');
        self::assertSame([], $this->queuedTags(), 'a refused write changes no page');
    }

    public function testAnInvalidTransitionRevalidatesNothing(): void
    {
        $event = $this->draftEvent();

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/status', $event->getId()), ['status' => 'nonsense']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->queuedTags());
    }

    public function testDeletingAGalleryPhotoRevalidatesTheEvent(): void
    {
        $event = $this->createEvent('ArchiLAN #4', new \DateTimeImmutable('2026-11-13 18:00'), new \DateTimeImmutable('2026-11-15 18:00'), published: true, photoGallery: ['https://example.org/a.jpg']);

        $this->client->request('DELETE', sprintf('/api/v1/admin/events/%s/gallery/0', $event->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame([['events', 'event:'.$event->getId()]], $this->queuedTags());
    }

    public function testPublishingAPostRevalidatesItsPageAndTheList(): void
    {
        $post = Post::draft('retour-archilan-3', 'Retour sur ArchiLAN #3', Post::TYPE_RECAP, 'Extrait.', ['Corps.'], '3 min', new \DateTimeImmutable('2026-10-01'));
        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/posts/%s/publish', $post->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame([['posts', 'post:retour-archilan-3']], $this->queuedTags());
    }

    public function testARefusedPostCreationRevalidatesNothing(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/posts', ['title' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->queuedTags());
    }

    public function testEachPartnerWriteRevalidatesThePartnerPages(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/partners', ['name' => 'Ludo', 'description' => '', 'url' => 'https://ludo.example', 'active' => true]);
        self::assertResponseStatusCodeSame(201);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsString($data['id']);
        // The test client boots a new kernel per request: the in-memory transport holds that request's messages only.
        self::assertSame([['partners']], $this->queuedTags());

        $this->client->jsonRequest('POST', '/api/v1/admin/partners/reorder', ['ids' => [$data['id']]]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame([['partners']], $this->queuedTags());

        $this->client->request('DELETE', sprintf('/api/v1/admin/partners/%s/logo', $data['id']));
        self::assertResponseStatusCodeSame(204);
        self::assertSame([['partners']], $this->queuedTags());
    }

    public function testARefusedPartnerWriteRevalidatesNothing(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/partners', ['name' => 'Ludo', 'description' => '', 'url' => 'javascript:alert(1)', 'active' => true]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->queuedTags());

        $this->client->request('DELETE', '/api/v1/admin/partners/unknown');
        self::assertResponseStatusCodeSame(404);

        self::assertSame([], $this->queuedTags());
    }

    public function testChoosingEventPartnersRevalidatesTheEventAndARefusalNothing(): void
    {
        $event = $this->createEvent('ArchiLAN #4', new \DateTimeImmutable('2026-11-13 18:00'), new \DateTimeImmutable('2026-11-15 18:00'), published: true);
        $url = sprintf('/api/v1/admin/events/%s/partners', $event->getId());

        $this->client->jsonRequest('PUT', $url, ['partners' => [['partnerId' => 'not-an-id', 'mention' => null]]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->queuedTags());

        $this->client->jsonRequest('PUT', $url, ['partners' => [['partnerId' => str_repeat('a', 32), 'mention' => null]]]);
        self::assertResponseIsSuccessful();
        self::assertSame([['events', 'event:'.$event->getId()]], $this->queuedTags());
    }

    private function draftEvent(): Event
    {
        return $this->createEvent('ArchiLAN #4', new \DateTimeImmutable('2026-11-13 18:00'), new \DateTimeImmutable('2026-11-15 18:00'));
    }

    /**
     * @return list<list<string>>
     */
    private function queuedTags(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $tags = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof RevalidatePublicPagesJob) {
                $tags[] = $message->tags;
            }
        }

        return $tags;
    }
}
