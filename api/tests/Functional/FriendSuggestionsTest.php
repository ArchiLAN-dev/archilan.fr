<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\Block;
use App\Community\Domain\Entity\Friendship;
use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Entity\SlotCoPlayer;

/**
 * Story 43.2: « Tu as joué avec » - friend suggestions from the games played together.
 */
final class FriendSuggestionsTest extends FunctionalTestCase
{
    private string $gameId;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable('2026-10-01T12:00:00+00:00');
        $this->gameId = $this->createGame('Game', 'game-slug')->getId();
    }

    public function testOneEventSplitInTwoSessionsIsStillOneLan(): void
    {
        // Story 43.19: the threshold counts events, not sessions.
        $me = $this->member('me');
        $other = $this->member('other');
        $event = $this->createEvent('Grosse LAN', $this->now, $this->now->modify('+3 days'), 20);
        $mine = $this->createRegistration($event->getId(), $me->getId());
        $theirs = $this->createRegistration($event->getId(), $other->getId());
        foreach ([$this->now, $this->now->modify('+1 day')] as $at) {
            $session = $this->session($event->getId(), $at);
            $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $mine->getId(), $this->gameId, 'Me', 0));
            $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $theirs->getId(), $this->gameId, 'Other', 0));
        }
        $this->entityManager->flush();

        $this->loginAs($me);
        $this->client->request('GET', '/api/v1/community/friend-suggestions?limit=10');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], array_column($this->data(), 'slug'));
    }

    public function testOneRunOrTwoEventSessionsMakeASuggestion(): void
    {
        $me = $this->member('me');
        $runMate = $this->member('runmate');
        $coPlayer = $this->member('coplayer');
        $lanTwice = $this->member('lantwice');
        $lanOnce = $this->member('lanonce');

        // A personal run with Runmate; Coplayer co-plays my slot.
        $run = $this->personalRun('Ma run', $me, [$runMate], coPlayersOfMine: [$coPlayer]);
        // Two LAN sessions with Lantwice, one of them with Lanonce too.
        $this->eventSession('LAN 1', [$me, $lanTwice, $lanOnce]);
        $this->eventSession('LAN 2', [$me, $lanTwice], $this->now->modify('+1 day'));
        $this->entityManager->flush();

        $this->loginAs($me);
        $this->client->request('GET', '/api/v1/community/friend-suggestions?limit=10');

        self::assertResponseStatusCodeSame(200);
        $data = $this->data();
        $slugs = array_column($data, 'slug');
        self::assertSame('lantwice', $slugs[0] ?? null, 'most sessions first');
        $others = array_slice($slugs, 1);
        sort($others);
        self::assertSame(['coplayer', 'runmate'], $others, 'one run is enough, co-players count; one LAN is not');
        self::assertSame(2, $data[0]['sessionsTogether']);
        self::assertSame('LAN 2', $data[0]['lastTitle'], 'the latest session together');
        self::assertSame('Ma run', $data[1]['lastTitle']);
        self::assertNotNull($run->getSessionId());
    }

    public function testFriendsRequestsBlocksAndDismissalsAreLeftOut(): void
    {
        $me = $this->member('me');
        $friend = $this->member('friend');
        $declined = $this->member('declined');
        $blocker = $this->member('blocker');
        $dismissed = $this->member('dismissed');
        $kept = $this->member('kept');
        $this->personalRun('Ma run', $me, [$friend, $declined, $blocker, $dismissed, $kept]);

        $accepted = Friendship::request($me->getId(), $friend->getId(), $this->now);
        $accepted->accept($this->now);
        $this->entityManager->persist($accepted);
        $refused = Friendship::request($declined->getId(), $me->getId(), $this->now);
        $refused->decline($this->now);
        $this->entityManager->persist($refused);
        $this->entityManager->persist(Block::create($blocker->getId(), $me->getId(), $this->now));
        $this->entityManager->flush();

        $this->loginAs($me);
        $this->client->request('POST', '/api/v1/community/friend-suggestions/dismissed/ignore');
        self::assertResponseStatusCodeSame(204);
        $this->client->request('POST', '/api/v1/community/friend-suggestions/dismissed/ignore');
        self::assertResponseStatusCodeSame(204, 'ignoring twice is a no-op');
        $this->client->request('POST', '/api/v1/community/friend-suggestions/me/ignore');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/v1/community/friend-suggestions');
        self::assertSame(['kept'], array_column($this->data(), 'slug'));
    }

    public function testASessionNarrowsToItsPlayersAndNeedsTheViewerInIt(): void
    {
        $me = $this->member('me');
        $here = $this->member('here');
        $elsewhere = $this->member('elsewhere');
        $outsider = $this->member('outsider');
        $run = $this->personalRun('Ici', $me, [$here]);
        $this->personalRun('Ailleurs', $me, [$elsewhere]);
        $foreign = $this->personalRun('Pas la mienne', $outsider, [$here]);
        $this->entityManager->flush();

        $this->loginAs($me);
        $this->client->request('GET', '/api/v1/community/friend-suggestions?sessionId='.$run->getSessionId());
        self::assertSame(['here'], array_column($this->data(), 'slug'));

        $this->client->request('GET', '/api/v1/community/friend-suggestions?sessionId='.$foreign->getSessionId());
        self::assertSame([], $this->data(), 'not a session I played');
    }

    public function testAnonymousIsRejected(): void
    {
        $this->client->request('GET', '/api/v1/community/friend-suggestions');
        self::assertResponseStatusCodeSame(401);
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function member(string $slug): User
    {
        return $this->createUser($slug.'@example.org', ['ROLE_USER'], ucfirst($slug), $slug);
    }

    /**
     * @param list<User> $players         one slot each
     * @param list<User> $coPlayersOfMine co-players of the owner's slot
     */
    private function personalRun(string $title, User $owner, array $players, array $coPlayersOfMine = []): Run
    {
        $run = Run::create($owner->getId(), $title, $this->now);
        $session = $this->session($run->getId(), $this->now);
        $run->attachSession($session->getId());
        $this->entityManager->persist($run);

        $ownerSlot = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $owner->getId(), $this->gameId, $owner->getDisplayName(), 0, bin2hex(random_bytes(16)));
        $this->entityManager->persist($ownerSlot);
        $gameSlotId = $ownerSlot->getSlotId();
        self::assertNotNull($gameSlotId);
        foreach ($coPlayersOfMine as $coPlayer) {
            $this->entityManager->persist(SlotCoPlayer::create(bin2hex(random_bytes(16)), $gameSlotId, $coPlayer->getId(), $this->now));
        }
        foreach ($players as $player) {
            $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $player->getId(), $this->gameId, $player->getDisplayName(), 0));
        }

        return $run;
    }

    /** @param list<User> $players */
    private function eventSession(string $title, array $players, ?\DateTimeImmutable $at = null): void
    {
        $event = $this->createEvent($title, $this->now, $this->now->modify('+3 days'), 20);
        $session = $this->session($event->getId(), $at ?? $this->now);
        foreach ($players as $player) {
            $registration = $this->createRegistration($event->getId(), $player->getId());
            $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $registration->getId(), $this->gameId, $player->getDisplayName(), 0));
        }
    }

    private function session(string $ownerId, \DateTimeImmutable $at): Session
    {
        $session = Session::create(bin2hex(random_bytes(16)), $ownerId, $at);
        $this->entityManager->persist($session);

        return $session;
    }

    /** @return list<array<mixed>> */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        $rows = [];
        foreach ($data as $row) {
            self::assertIsArray($row);
            $rows[] = $row;
        }

        return $rows;
    }
}
