<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\PersonalRuns\Domain\Entity\Run;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Entity\SlotCoPlayer;

/**
 * Story 16.17: a slot played by several people scores for all of them.
 *
 * `session_slot.registration_id` names who *declared* a slot, and every aggregate read it directly:
 * XP, level, leaderboard and achievements all counted a shared game for exactly one member. These
 * tests pin the fix where it matters - the numbers a player sees - rather than at the SQL that
 * produces them.
 */
final class SlotCoPlayerScoringTest extends FunctionalTestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable('2026-08-25T10:00:00+00:00');
    }

    public function testCoPlayerOfAnEventSlotScoresItsChecksAndGoal(): void
    {
        $owner = $this->createUser('owner@example.org', ['ROLE_USER'], 'Owner', 'owner');
        $mate = $this->createUser('mate@example.org', ['ROLE_USER'], 'Mate', 'mate');

        $event = $this->createEvent('LAN', $this->now, $this->now->modify('+1 day'));
        $game = $this->createGame('Minecraft', 'minecraft');
        $reg = $this->createRegistration($event->getId(), $owner->getId());

        $session = $this->finishedSession($event->getId());
        $slot = $this->slot($session->getId(), $reg->getId(), $game->getId(), 'Owner_MC', 'game-slot-1');
        $slot->recordGoal($this->now->modify('+1 hour'));
        $slot->recordProgress(50, 30);
        $this->entityManager->persist($slot);
        $this->entityManager->persist(SlotCoPlayer::create(bin2hex(random_bytes(16)), 'game-slot-1', $mate->getId(), $this->now));
        $this->entityManager->flush();

        // The co-player never declared a slot, yet they played that game and finished it.
        $stats = $this->statsOf('mate');
        self::assertSame(1, $stats['runsParticipated']);
        self::assertSame(1, $stats['goalCompletions']);
        self::assertSame(50, $stats['totalChecksDone']);
        self::assertSame(30, $stats['totalItemsReceived']);

        // And the owner keeps everything: sharing is not splitting.
        $ownerStats = $this->statsOf('owner');
        self::assertSame(1, $ownerStats['goalCompletions']);
        self::assertSame(50, $ownerStats['totalChecksDone']);
    }

    public function testCoPlayerOfAPersonalRunSlotScoresIt(): void
    {
        $owner = $this->createUser('powner@example.org', ['ROLE_USER'], 'POwner', 'powner');
        $mate = $this->createUser('pmate@example.org', ['ROLE_USER'], 'PMate', 'pmate');
        $game = $this->createGame('Minecraft PR', 'minecraft-pr');

        $session = $this->finishedPersonalRunSession($owner->getId());
        $slot = $this->slot($session->getId(), $owner->getId(), $game->getId(), 'POwner_MC', 'game-slot-2');
        $slot->recordGoal($this->now->modify('+1 hour'));
        $slot->recordProgress(40, 20);
        $this->entityManager->persist($slot);
        $this->entityManager->persist(SlotCoPlayer::create(bin2hex(random_bytes(16)), 'game-slot-2', $mate->getId(), $this->now));
        $this->entityManager->flush();

        $stats = $this->statsOf('pmate');
        // A personal run only counts once its player reached a goal in it (story 17.15). The gate
        // applies to the player, not to the slot's owner, so the co-player passes it on their own.
        self::assertSame(1, $stats['runsParticipated']);
        self::assertSame(1, $stats['goalCompletions']);
        self::assertSame(40, $stats['totalChecksDone']);
    }

    /**
     * AC 9: `runs_participated` counts sessions, not slots. Someone playing two slots of the same
     * party must not see it counted twice.
     */
    public function testTwoSlotsOfTheSameRunCountAsOneRun(): void
    {
        $owner = $this->createUser('duo@example.org', ['ROLE_USER'], 'Duo', 'duo');
        $mate = $this->createUser('duomate@example.org', ['ROLE_USER'], 'DuoMate', 'duomate');
        $game = $this->createGame('Duo Game', 'duo-game');

        $session = $this->finishedPersonalRunSession($owner->getId());

        $first = $this->slot($session->getId(), $owner->getId(), $game->getId(), 'Duo_A', 'game-slot-a');
        $first->recordGoal($this->now->modify('+1 hour'));
        $first->recordProgress(10, 5);
        $this->entityManager->persist($first);

        $second = $this->slot($session->getId(), $owner->getId(), $game->getId(), 'Duo_B', 'game-slot-b', 1);
        $second->recordProgress(7, 3);
        $this->entityManager->persist($second);

        foreach (['game-slot-a', 'game-slot-b'] as $gameSlotId) {
            $this->entityManager->persist(SlotCoPlayer::create(bin2hex(random_bytes(16)), $gameSlotId, $mate->getId(), $this->now));
        }
        $this->entityManager->flush();

        $stats = $this->statsOf('duomate');
        self::assertSame(1, $stats['runsParticipated']);
        self::assertSame(17, $stats['totalChecksDone']);
    }

    /**
     * AC 10: a slot released without a goal is excluded for everyone. The new path must not become
     * a way around a guard that already exists.
     */
    public function testAReleasedSlotWithoutGoalCountsForNobody(): void
    {
        $owner = $this->createUser('rel@example.org', ['ROLE_USER'], 'Rel', 'rel');
        $mate = $this->createUser('relmate@example.org', ['ROLE_USER'], 'RelMate', 'relmate');

        $event = $this->createEvent('LAN Rel', $this->now, $this->now->modify('+1 day'));
        $game = $this->createGame('Released Game', 'released-game');
        $reg = $this->createRegistration($event->getId(), $owner->getId());

        $session = $this->finishedSession($event->getId());
        $slot = $this->slot($session->getId(), $reg->getId(), $game->getId(), 'Rel_A', 'game-slot-rel');
        $slot->recordProgress(99, 99);
        $slot->markAsReleased();
        $this->entityManager->persist($slot);
        $this->entityManager->persist(SlotCoPlayer::create(bin2hex(random_bytes(16)), 'game-slot-rel', $mate->getId(), $this->now));
        $this->entityManager->flush();

        $stats = $this->statsOf('relmate');
        self::assertSame(0, $stats['goalCompletions']);
        self::assertSame(0, $stats['totalChecksDone']);
    }

    /** AC 11: the public leaderboard counts the co-player too, on both aggregate axes. */
    public function testTheLeaderboardIncludesCoPlayers(): void
    {
        $owner = $this->createUser('lowner@example.org', ['ROLE_USER'], 'LOwner', 'lowner');
        $mate = $this->createUser('lmate@example.org', ['ROLE_USER'], 'LMate', 'lmate');
        $game = $this->createGame('Board Game', 'board-game');

        $session = $this->finishedPersonalRunSession($owner->getId());
        $slot = $this->slot($session->getId(), $owner->getId(), $game->getId(), 'LOwner_A', 'game-slot-lb');
        $slot->recordGoal($this->now->modify('+1 hour'));
        $slot->recordProgress(12, 6);
        $this->entityManager->persist($slot);
        $this->entityManager->persist(SlotCoPlayer::create(bin2hex(random_bytes(16)), 'game-slot-lb', $mate->getId(), $this->now));
        $this->entityManager->flush();

        self::assertSame(1, $this->leaderboardValue('goals', 'lmate'));
        self::assertSame(12, $this->leaderboardValue('checks', 'lmate'));
    }

    /**
     * Story 16.18: a slot of an imported archive that nobody was put on has no owner. Its empty
     * owner key must stay out of the aggregates rather than become a phantom player.
     */
    public function testAnUnassignedImportedSlotScoresForNobody(): void
    {
        $owner = $this->createUser('unassigned@example.org', ['ROLE_USER'], 'Unassigned', 'unassigned');
        $game = $this->createGame('Orphan Game', 'orphan-game');

        $session = $this->finishedPersonalRunSession($owner->getId());
        $orphan = $this->slot($session->getId(), '', $game->getId(), 'Nobody_A', 'game-slot-orphan');
        $orphan->recordGoal($this->now->modify('+1 hour'));
        $orphan->recordProgress(99, 99);
        $this->entityManager->persist($orphan);
        $this->entityManager->flush();

        // The owner played nothing in this run, so nothing lands on them either.
        $stats = $this->statsOf('unassigned');
        self::assertSame(0, $stats['goalCompletions']);
        self::assertSame(0, $stats['totalChecksDone']);
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    /**
     * @return array<string, int>
     */
    private function statsOf(string $slug): array
    {
        $this->client->request('GET', '/api/v1/players/'.$slug);
        self::assertResponseStatusCodeSame(200);

        $body = $this->decodedJsonResponse();
        $data = $body['data'];
        self::assertIsArray($data);
        $stats = $data['stats'];
        self::assertIsArray($stats);

        $out = [];
        foreach (['runsParticipated', 'goalCompletions', 'totalChecksDone', 'totalItemsReceived'] as $key) {
            $value = $stats[$key] ?? null;
            $out[$key] = is_int($value) ? $value : 0;
        }

        return $out;
    }

    private function leaderboardValue(string $axis, string $slug): ?int
    {
        $this->client->request('GET', '/api/v1/leaderboard?axis='.$axis);
        self::assertResponseStatusCodeSame(200);

        $body = $this->decodedJsonResponse();
        $entries = $body['data'];
        self::assertIsArray($entries);

        foreach ($entries as $entry) {
            if (is_array($entry) && ($entry['slug'] ?? null) === $slug) {
                $value = $entry['value'] ?? null;

                return is_int($value) ? $value : null;
            }
        }

        return null;
    }

    private function slot(string $sessionId, string $ownerKey, string $gameId, string $slotName, string $gameSlotId, int $order = 0): SessionSlot
    {
        return SessionSlot::create(
            bin2hex(random_bytes(16)),
            $sessionId,
            $ownerKey,
            $gameId,
            $slotName,
            $order,
            $gameSlotId,
        );
    }

    private function finishedPersonalRunSession(string $ownerId): Session
    {
        $event = $this->createEvent('Perso '.bin2hex(random_bytes(4)), $this->now, $this->now->modify('+1 day'));
        $session = $this->finishedSession($event->getId());

        $run = Run::create($ownerId, 'Ma run', $this->now);
        $run->attachSession($session->getId());
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $session;
    }

    private function finishedSession(string $eventId): Session
    {
        $session = Session::create(bin2hex(random_bytes(16)), $eventId, $this->now);
        $session->transition(Session::STATUS_VALIDATING, $this->now);
        $session->transition(Session::STATUS_READY, $this->now);
        $session->transition(Session::STATUS_GENERATING, $this->now);
        $session->transition(Session::STATUS_GENERATED, $this->now);
        $session->transition(Session::STATUS_LAUNCHING, $this->now);
        $session->transition(Session::STATUS_RUNNING, $this->now, 'bridge.local', 38281, 'secret', 5000);
        $session->transition(Session::STATUS_FINISHED, $this->now->modify('+2 hours'));
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }
}
