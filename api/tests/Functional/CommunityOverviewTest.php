<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\AchievementGrant;
use App\PersonalRuns\Domain\Entity\Run;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;

/**
 * The /communaute hub's own endpoint (story 30.38).
 */
final class CommunityOverviewTest extends FunctionalTestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable('2026-08-01T10:00:00+00:00');
    }

    public function testIsReadableAnonymouslyAndCountsListableMembers(): void
    {
        $this->createUser('alice@example.org', slug: 'alice');
        $this->createUser('bob@example.org', slug: 'bob');

        $this->client->jsonRequest('GET', '/api/v1/community/overview');

        self::assertResponseIsSuccessful();
        $data = $this->data();
        self::assertSame(2, $data['memberCount']);
        self::assertSame([], $data['playingNow']);
        self::assertSame([], $data['recentAchievements']);
    }

    public function testPlayingNowNamesTheGameOfAPublicEventSession(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $event = $this->createEvent('LAN', $this->now, $this->now->modify('+1 day'), isPublic: true);
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $registration = $this->createRegistration($event->getId(), $alice->getId());

        $session = $this->makeRunningSession($event->getId());
        $this->attachSlot($session->getId(), $registration->getId(), $game->getId());

        $this->client->jsonRequest('GET', '/api/v1/community/overview');

        self::assertResponseIsSuccessful();
        $playing = $this->data()['playingNow'];
        self::assertIsArray($playing);
        self::assertCount(1, $playing);
        self::assertIsArray($playing[0]);
        self::assertSame('alice', $playing[0]['slug']);
        self::assertSame('Hollow Knight', $playing[0]['game']);
    }

    public function testPlayingNowHidesTheGameOfAnUnpublishedPersonalRun(): void
    {
        // The player is shown as playing - that is public - but an anonymous visitor must not learn
        // what an unpublished personal run is running.
        $bob = $this->createUser('bob@example.org', slug: 'bob');
        $game = $this->createGame('Celeste', 'celeste');

        $run = Run::create($bob->getId(), 'Ma run', $this->now);
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        // A personal-run session carries the run id in event_id, and its slot holds the user id
        // directly (no registration) - the shape DbalCommunityPresenceQuery reads.
        $session = $this->makeRunningSession($run->getId());
        $run->attachSession($session->getId());
        $this->entityManager->flush();
        $this->attachSlot($session->getId(), $bob->getId(), $game->getId());

        $this->client->jsonRequest('GET', '/api/v1/community/overview');

        self::assertResponseIsSuccessful();
        $playing = $this->data()['playingNow'];
        self::assertIsArray($playing);
        self::assertCount(1, $playing);
        self::assertIsArray($playing[0]);
        self::assertSame('bob', $playing[0]['slug']);
        self::assertNull($playing[0]['game'], 'an unpublished personal run must not name its game');
    }

    public function testRecentAchievementsListNewestFirstWithTheirOwner(): void
    {
        $this->seedDefaultAchievementDefinitions();
        $alice = $this->createUser('alice@example.org', slug: 'alice');

        $this->entityManager->persist(AchievementGrant::grant($alice->getId(), 'first_run', $this->now->modify('-2 days')));
        $this->entityManager->persist(AchievementGrant::grant($alice->getId(), 'regular', $this->now));
        $this->entityManager->flush();

        $this->client->jsonRequest('GET', '/api/v1/community/overview');

        self::assertResponseIsSuccessful();
        $recent = $this->data()['recentAchievements'];
        self::assertIsArray($recent);
        self::assertCount(2, $recent);
        self::assertIsArray($recent[0]);
        self::assertSame('regular', $recent[0]['achievementKey']);
        self::assertSame('alice', $recent[0]['slug']);
        self::assertNotSame('', $recent[0]['name']);
    }

    private function makeRunningSession(string $eventId): Session
    {
        $session = Session::create(bin2hex(random_bytes(16)), $eventId, $this->now);
        foreach ([
            Session::STATUS_VALIDATING,
            Session::STATUS_READY,
            Session::STATUS_GENERATING,
            Session::STATUS_GENERATED,
            Session::STATUS_LAUNCHING,
        ] as $step) {
            $session->transition($step, $this->now);
        }
        $session->transition(Session::STATUS_RUNNING, $this->now, 'bridge.local', 38281, 'secret', 5000);
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    private function attachSlot(string $sessionId, string $registrationId, string $gameId): void
    {
        $this->entityManager->persist(
            SessionSlot::create(bin2hex(random_bytes(16)), $sessionId, $registrationId, $gameId, 'Player', 0),
        );
        $this->entityManager->flush();
    }

    /**
     * @return array<mixed>
     */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }
}
