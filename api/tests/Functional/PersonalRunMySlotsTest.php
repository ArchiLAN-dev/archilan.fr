<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\PersonalRuns\Domain\Entity\Run;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Entity\SlotCoPlayer;

/**
 * Story 17.29: an active run tells the caller which slot name(s) to type in their client - the slots
 * they own and the ones they co-play, never another player's.
 */
final class PersonalRunMySlotsTest extends FunctionalTestCase
{
    public function testAnActiveRunListsTheCallersOwnAndCoPlayedSlots(): void
    {
        $alice = $this->createUser('alice@example.com', displayName: 'Alice');
        $bob = $this->createUser('bob@example.com', displayName: 'Bob');
        $carol = $this->createUser('carol@example.com', displayName: 'Carol');
        $emerald = $this->createGame('Pokemon Emerald', 'pokemon-emerald');
        $hollow = $this->createGame('Hollow Knight', 'hollow-knight');

        $run = $this->createActiveRunWithSession($alice->getId(), 'sess-my-slots');
        $this->entityManager->persist(SessionSlot::create('row-alice', 'sess-my-slots', $alice->getId(), $emerald->getId(), 'Alice_PE', 1, 'slot-alice'));
        $this->entityManager->persist(SessionSlot::create('row-bob', 'sess-my-slots', $bob->getId(), $emerald->getId(), 'Bob_PE', 2, 'slot-bob'));
        $this->entityManager->persist(SessionSlot::create('row-carol', 'sess-my-slots', $carol->getId(), $hollow->getId(), 'Carol_HK', 3, 'slot-carol'));
        // Alice also plays Carol's slot (story 16.17).
        $this->entityManager->persist(SlotCoPlayer::create('cp-1', 'slot-carol', $alice->getId(), new \DateTimeImmutable('2026-05-12T10:00:00+00:00')));
        $this->entityManager->flush();

        $this->loginAs($alice);
        $this->client->request('GET', '/api/v1/runs/'.$run->getId());

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame([
            ['name' => 'Alice_PE', 'game' => 'Pokemon Emerald'],
            ['name' => 'Carol_HK', 'game' => 'Hollow Knight'],
        ], $data['mySlots']);
    }

    public function testAnInactiveRunListsNoSlot(): void
    {
        $alice = $this->createUser('alice@example.com', displayName: 'Alice');
        $run = $this->createActiveRunWithSession($alice->getId(), 'sess-idle');
        new \ReflectionProperty(Run::class, 'status')->setValue($run, Run::STATUS_IDLE);
        $this->entityManager->persist(SessionSlot::create('row-alice', 'sess-idle', $alice->getId(), 'game-x', 'Alice_PE', 1, 'slot-alice'));
        $this->entityManager->flush();

        $this->loginAs($alice);
        $this->client->request('GET', '/api/v1/runs/'.$run->getId());

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame([], $data['mySlots']);
    }

    private function createActiveRunWithSession(string $ownerId, string $sessionId): Run
    {
        $run = Run::create($ownerId, 'Test Run', new \DateTimeImmutable('2026-05-12T10:00:00+00:00'));
        $run->attachSession($sessionId);
        new \ReflectionProperty(Run::class, 'status')->setValue($run, Run::STATUS_ACTIVE);
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }
}
