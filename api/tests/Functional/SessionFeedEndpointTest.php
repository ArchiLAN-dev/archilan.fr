<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionFeedEvent;

final class SessionFeedEndpointTest extends FunctionalTestCase
{
    private const string SECRET = 'test-runner-secret'; // matches CENTRAL_API_SECRET in .env.test

    public function testFeedPushPersistsItemEventsAndIgnoresNonItemEvents(): void
    {
        $sessionId = 'run-feed-persist-1';
        $uri = sprintf('/api/v1/internal/sessions/%s/feed-push', $sessionId);

        // An item event (item_sent -> item-received) with structured origin is persisted.
        $this->client->jsonRequest('POST', $uri, [
            'type' => 'item_sent',
            'text' => 'Alice found Master Sword for Bob',
            'timestamp' => '2026-05-01T10:00:30+00:00',
            'item' => ['id' => 42, 'name' => 'Master Sword', 'flags' => 1],
            'location' => ['id' => 10, 'name' => 'Chest'],
            'sender' => ['slot' => 1, 'name' => 'Alice', 'game' => 'ALTTP'],
            'receiver' => ['slot' => 2, 'name' => 'Bob', 'game' => 'SM64'],
        ], ['HTTP_X_INTERNAL_SECRET' => self::SECRET]);
        self::assertResponseStatusCodeSame(200);

        // A chat/system event is accepted (republished) but NOT persisted.
        $this->client->jsonRequest('POST', $uri, [
            'type' => 'chat',
            'text' => 'gg',
            'timestamp' => '2026-05-01T10:00:40+00:00',
        ], ['HTTP_X_INTERNAL_SECRET' => self::SECRET]);
        self::assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(SessionFeedEvent::class)->findBy(['sessionId' => $sessionId]);
        self::assertCount(1, $stored, 'only the item event is persisted');
        $event = $stored[0];
        self::assertSame('item-received', $event->getType());
        self::assertSame('Master Sword', $event->getItemName());
        self::assertSame(1, $event->getItemFlags());
        self::assertSame('Chest', $event->getLocationName());
        self::assertSame(1, $event->getSenderSlot());
        self::assertSame('SM64', $event->getReceiverGame());
    }

    public function testFeedPushPersistsHintAndGoalEvents(): void
    {
        $sessionId = 'run-feed-persist-2';
        $uri = sprintf('/api/v1/internal/sessions/%s/feed-push', $sessionId);

        // A hint carries the same origin shape as an item event (story 32.12).
        $this->client->jsonRequest('POST', $uri, [
            'type' => 'hint',
            'text' => "[Hint]: Bob's Master Sword is at Chest",
            'timestamp' => '2026-05-01T10:01:00+00:00',
            'item' => ['id' => 42, 'name' => 'Master Sword', 'flags' => 1],
            'location' => ['id' => 10, 'name' => 'Chest'],
            'sender' => ['slot' => 1, 'name' => 'Alice', 'game' => 'ALTTP'],
            'receiver' => ['slot' => 2, 'name' => 'Bob', 'game' => 'SM64'],
        ], ['HTTP_X_INTERNAL_SECRET' => self::SECRET]);
        self::assertResponseStatusCodeSame(200);

        // A goal only carries the finishing player.
        $this->client->jsonRequest('POST', $uri, [
            'type' => 'goal',
            'text' => 'Bob has completed their goal',
            'timestamp' => '2026-05-01T11:00:00+00:00',
            'sender' => ['slot' => 2, 'name' => 'Bob', 'game' => 'SM64'],
        ], ['HTTP_X_INTERNAL_SECRET' => self::SECRET]);
        self::assertResponseStatusCodeSame(200);

        // Join/part/system noise still stays out.
        $this->client->jsonRequest('POST', $uri, [
            'type' => 'join',
            'text' => 'Bob joined',
            'timestamp' => '2026-05-01T11:00:10+00:00',
        ], ['HTTP_X_INTERNAL_SECRET' => self::SECRET]);
        self::assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(SessionFeedEvent::class)
            ->findBy(['sessionId' => $sessionId], ['occurredAt' => 'ASC']);
        self::assertCount(2, $stored, 'hint and goal are persisted, join is not');

        [$hint, $goal] = $stored;
        self::assertSame('hint', $hint->getType());
        self::assertSame('Master Sword', $hint->getItemName());
        self::assertSame(1, $hint->getItemFlags());
        self::assertSame(1, $hint->getSenderSlot());
        self::assertSame(2, $hint->getReceiverSlot());

        self::assertSame('goal', $goal->getType());
        self::assertSame(2, $goal->getSenderSlot());
        self::assertSame('Bob', $goal->getSenderName());
        self::assertNull($goal->getItemId());
        self::assertNull($goal->getLocationId());
    }

    public function testPersonalRunFeedIsPrivateByDefaultButOwnerParticipantAndPublishExposeIt(): void
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $owner = $this->createUser('feedowner@example.org', displayName: 'Owner');
        $participant = $this->createUser('feedpart@example.org', displayName: 'Participant');
        $stranger = $this->createUser('feedstranger@example.org', displayName: 'Stranger');

        [$run, $session] = $this->createPersonalRunWithFeed($now, $owner);
        $this->entityManager->persist(RunParticipant::create($run->getId(), $participant->getId(), $now));
        $this->entityManager->flush();

        $url = sprintf('/api/v1/parties/%s/feed', $session->getId());

        // Anonymous: a private run's feed must not leak.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);

        // Owner sees it, with the event.
        $this->loginAs($owner);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertCount(1, $data);

        // Participant sees it.
        $this->loginAs($participant);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);

        // A stranger does not.
        $this->loginAs($stranger);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);

        // Once the owner publishes, anyone can see it. Publish via the endpoint (not a direct entity
        // write) - after several client requests reboot the kernel, the test's entity manager is stale.
        $this->loginAs($owner);
        $this->client->jsonRequest('PUT', sprintf('/api/v1/runs/%s/recap-visibility', $run->getId()), ['public' => true]);
        self::assertResponseStatusCodeSame(200);
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);
    }

    /**
     * @return array{Run, Session}
     */
    private function createPersonalRunWithFeed(\DateTimeImmutable $now, User $owner): array
    {
        $run = Run::create($owner->getId(), 'Ma run privée', $now);
        $this->entityManager->persist($run);

        $session = Session::create(bin2hex(random_bytes(16)), $run->getId(), $now);
        $this->entityManager->persist($session);

        $this->entityManager->persist(new SessionFeedEvent(
            bin2hex(random_bytes(16)),
            $session->getId(),
            'item-received',
            'Owner found their Key',
            $now->modify('+30 seconds'),
            7,
            'Key',
            1,
            3,
            'Basement',
            1,
            'Owner',
            "Luigi's Mansion",
            1,
            'Owner',
            "Luigi's Mansion",
        ));
        $this->entityManager->flush();

        return [$run, $session];
    }
}
