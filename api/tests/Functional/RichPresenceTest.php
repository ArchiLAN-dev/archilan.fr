<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionPlayersSnapshot;
use App\Sessions\Domain\Entity\SessionSlot;

/**
 * Story 43.7: where a member stands in the slot their presence shows, from the bridge's last players push.
 */
final class RichPresenceTest extends FunctionalTestCase
{
    public function testTheProgressOfTheSlotShownIsGiven(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $session = $this->eventSession($alice, 'AliceHK');
        $this->snapshot($session, ['1' => ['slot_name' => 'AliceHK', 'checks_done' => 21, 'checks_total' => 50, 'reachable_now' => 4, 'client_status' => 20]]);

        $presence = $this->presence('alice');
        self::assertTrue($presence['playing']);
        self::assertSame('playing', $presence['slotState']);
        self::assertSame(42, $presence['progressPercent']);
    }

    public function testNoReachableCheckLeftIsABk(): void
    {
        $bob = $this->createUser('bob@example.org', slug: 'bob');
        $session = $this->eventSession($bob, 'BobHK');
        $this->snapshot($session, ['1' => ['slot_name' => 'BobHK', 'checks_done' => 30, 'checks_total' => 50, 'reachable_now' => 0, 'client_status' => 20]]);

        $presence = $this->presence('bob');
        self::assertSame('bk', $presence['slotState']);
        self::assertSame(60, $presence['progressPercent']);
    }

    public function testAGoalJustReachedStaysShownAndAStillPlayedSlotComesFirst(): void
    {
        $carol = $this->createUser('carol@example.org', slug: 'carol');
        $session = $this->eventSession($carol, 'CarolHK');
        $slot = $this->slotOf($session);
        $slot->recordGoal(new \DateTimeImmutable('-10 minutes'));
        $this->entityManager->flush();

        $presence = $this->presence('carol');
        self::assertTrue($presence['playing'], 'a goal reached ten minutes ago still shows');
        self::assertSame('goal', $presence['slotState']);

        $celeste = $this->createGame('Celeste', 'celeste');
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $slot->getRegistrationId(), $celeste->getId(), 'CarolCeleste', 1));
        $this->entityManager->flush();

        $presence = $this->presence('carol');
        self::assertSame('Celeste', $presence['game'], 'a slot still played comes before a goal reached');
        self::assertSame('unknown', $presence['slotState'], 'no snapshot yet');
        self::assertNull($presence['progressPercent']);
    }

    public function testAnImportedSeedShowsTheGameOnly(): void
    {
        $dave = $this->createUser('dave@example.org', slug: 'dave');
        $run = Run::create($dave->getId(), 'Seed importée', new \DateTimeImmutable());
        $run->importSeed('imports/seed.zip', [], new \DateTimeImmutable());
        $this->entityManager->persist($run);
        $session = Session::createRunning(bin2hex(random_bytes(16)), $run->getId(), 'bridge.local', 38281, 'secret', 5000, new \DateTimeImmutable('-5 minutes'));
        $this->entityManager->persist($session);
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $dave->getId(), $game->getId(), 'DaveHK', 0));
        $this->entityManager->flush();
        $this->snapshot($session, ['1' => ['slot_name' => 'DaveHK', 'checks_done' => 10, 'checks_total' => 50, 'reachable_now' => 0]]);

        $presence = $this->presence('dave');
        self::assertSame('Hollow Knight', $presence['game']);
        self::assertSame('unknown', $presence['slotState']);
        self::assertNull($presence['progressPercent']);
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function eventSession(User $player, string $slotName): Session
    {
        $now = new \DateTimeImmutable('-5 minutes');
        $event = $this->createEvent('LAN', $now, $now->modify('+1 day'), published: true, isPublic: true);
        $registration = $this->createRegistration($event->getId(), $player->getId());
        $session = Session::createRunning(bin2hex(random_bytes(16)), $event->getId(), 'bridge.local', 38281, 'secret', 5000, $now);
        $this->entityManager->persist($session);
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $registration->getId(), $game->getId(), $slotName, 0));
        $this->entityManager->flush();

        return $session;
    }

    private function slotOf(Session $session): SessionSlot
    {
        $slot = $this->entityManager->getRepository(SessionSlot::class)->findOneBy(['sessionId' => $session->getId(), 'slotOrder' => 0]);
        self::assertInstanceOf(SessionSlot::class, $slot);

        return $slot;
    }

    /** @param array<array-key, array<string, mixed>> $slots */
    private function snapshot(Session $session, array $slots): void
    {
        $this->entityManager->persist(new SessionPlayersSnapshot($session->getId(), ['slots' => $slots], new \DateTimeImmutable()));
        $this->entityManager->flush();
    }

    /** @return array<mixed> */
    private function presence(string $slug): array
    {
        $this->client->request('GET', '/api/v1/community/profiles/'.$slug);
        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $presence = $data['presence'] ?? null;
        self::assertIsArray($presence);

        return $presence;
    }
}
