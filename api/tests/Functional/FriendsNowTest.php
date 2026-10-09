<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\CommunityProfile;
use App\Community\Domain\Entity\FriendFavorite;
use App\Community\Domain\Entity\Friendship;
use App\Community\Domain\Enum\PresenceVisibility;
use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;

/**
 * Story 43.5: « Mes amis en ce moment », playing first then active in the last day.
 */
final class FriendsNowTest extends FunctionalTestCase
{
    private User $viewer;

    private ?string $gameId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->viewer = $this->createUser('viewer@example.org', displayName: 'Viewer', slug: 'viewer');
    }

    public function testPlayingFriendsComeWithWhatTheViewerMayKnowOfTheirSession(): void
    {
        $now = new \DateTimeImmutable('-5 minutes');
        $public = $this->createEvent('LAN publique', $now, $now->modify('+1 day'), published: true, isPublic: true);
        $private = $this->createEvent('LAN privée', $now, $now->modify('+1 day'), published: true, isPublic: false);
        $this->playEvent($this->friend('alice'), $public->getId());
        $this->playEvent($this->friend('bob'), $private->getId());

        $shared = $this->personalRun($this->friend('carol'), 'Run commune');
        $this->entityManager->persist(RunParticipant::create($shared->getId(), $this->viewer->getId(), new \DateTimeImmutable()));
        $this->playRun('carol', $shared);
        $this->playRun('dave', $this->personalRun($this->friend('dave'), 'Run secrète'));
        $this->playEvent($this->createUser('stranger@example.org', slug: 'stranger'), $public->getId());

        $data = $this->friendsNow();
        self::assertTrue($data['hasFriends']);
        $bySlug = [];
        foreach ($this->rows($data['playing']) as $row) {
            self::assertIsString($row['slug']);
            $bySlug[$row['slug']] = [$row['game'], $row['kind'], $row['title'], $row['eventId'], $row['runId']];
        }

        self::assertSame([
            'alice' => ['Hollow Knight', 'event', 'LAN publique', $public->getId(), null],
            'bob' => ['Hollow Knight', 'event', null, null, null],
            'carol' => ['Hollow Knight', 'run', 'Run commune', null, $shared->getId()],
            'dave' => ['Hollow Knight', 'run', null, null, null],
        ], $bySlug, 'the game always, the title and the page only with access; a stranger is not listed');
        self::assertSame([], $data['recent']);
    }

    public function testFriendsActiveInTheLastDayFollowAndADiscreetFriendIsLeftOut(): void
    {
        $this->finishedEvent($this->friend('recent'), new \DateTimeImmutable('-2 hours'));
        $this->finishedEvent($this->friend('old'), new \DateTimeImmutable('-2 days'));
        $hidden = $this->friend('hidden');
        $this->finishedEvent($hidden, new \DateTimeImmutable('-1 hour'));
        $profile = CommunityProfile::create($hidden->getId(), new \DateTimeImmutable());
        $profile->choosePresenceVisibility(PresenceVisibility::Nobody, new \DateTimeImmutable());
        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        $data = $this->friendsNow();
        self::assertSame([], $data['playing']);
        $recent = $this->rows($data['recent']);
        self::assertSame(['recent'], array_column($recent, 'slug'));
        self::assertSame('Hollow Knight', $recent[0]['game']);
        self::assertArrayNotHasKey('runId', $recent[0], 'no link for a past session');
    }

    public function testStarredFriendsComeFirst(): void
    {
        $this->finishedEvent($this->friend('newest'), new \DateTimeImmutable('-1 hour'));
        $starred = $this->friend('starred');
        $this->finishedEvent($starred, new \DateTimeImmutable('-5 hours'));
        $this->entityManager->persist(FriendFavorite::create($this->viewer->getId(), $starred->getId(), new \DateTimeImmutable()));
        $this->entityManager->flush();

        $recent = $this->rows($this->friendsNow()['recent']);
        self::assertSame(['starred', 'newest'], array_column($recent, 'slug'), 'story 43.11a: the star before the most recent');
        self::assertSame([true, false], array_column($recent, 'isFavorite'));
    }

    public function testAViewerWithoutFriendsIsToldSo(): void
    {
        self::assertSame(['hasFriends' => false, 'playing' => [], 'recent' => []], $this->friendsNow());
    }

    public function testAnAnonymousVisitorGetsNothing(): void
    {
        $this->client->request('GET', '/api/v1/community/friends/now');
        self::assertResponseStatusCodeSame(401);
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    /** @return array<mixed> */
    private function friendsNow(): array
    {
        $this->loginAs($this->viewer);
        $this->client->request('GET', '/api/v1/community/friends/now');
        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }

    /** @return list<array<mixed>> */
    private function rows(mixed $rows): array
    {
        self::assertIsArray($rows);
        $list = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            $list[] = $row;
        }

        return $list;
    }

    private function friend(string $slug): User
    {
        $user = $this->createUser($slug.'@example.org', displayName: ucfirst($slug), slug: $slug);
        $friendship = Friendship::request($user->getId(), $this->viewer->getId(), new \DateTimeImmutable());
        $friendship->accept(new \DateTimeImmutable());
        $this->entityManager->persist($friendship);
        $this->entityManager->flush();

        return $user;
    }

    private function gameId(): string
    {
        return $this->gameId ??= $this->createGame('Hollow Knight', 'hollow-knight')->getId();
    }

    private function playEvent(User $player, string $eventId): void
    {
        $registration = $this->createRegistration($eventId, $player->getId());
        $session = Session::createRunning(bin2hex(random_bytes(16)), $eventId, 'bridge.local', 38281, 'secret', 5000, new \DateTimeImmutable('-5 minutes'));
        $this->entityManager->persist($session);
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $registration->getId(), $this->gameId(), $player->getDisplayName(), 0));
        $this->entityManager->flush();
    }

    private function personalRun(User $owner, string $title): Run
    {
        $run = Run::create($owner->getId(), $title, new \DateTimeImmutable());
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    private function playRun(string $slug, Run $run): void
    {
        $session = Session::createRunning(bin2hex(random_bytes(16)), $run->getId(), 'bridge.local', 38281, 'secret', 5000, new \DateTimeImmutable('-5 minutes'));
        $this->entityManager->persist($session);
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $run->getOwnerId(), $this->gameId(), $slug, 0));
        $this->entityManager->flush();
    }

    private function finishedEvent(User $player, \DateTimeImmutable $finishedAt): void
    {
        $event = $this->createEvent('LAN '.$player->getDisplayName(), $finishedAt->modify('-1 day'), $finishedAt, published: true, isPublic: true);
        $registration = $this->createRegistration($event->getId(), $player->getId());
        $session = Session::createRunning(bin2hex(random_bytes(16)), $event->getId(), 'bridge.local', 38281, 'secret', 5000, $finishedAt->modify('-3 hours'));
        $session->transition(Session::STATUS_FINISHED, $finishedAt);
        $this->entityManager->persist($session);
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $registration->getId(), $this->gameId(), $player->getDisplayName(), 0));
        $this->entityManager->flush();
    }
}
