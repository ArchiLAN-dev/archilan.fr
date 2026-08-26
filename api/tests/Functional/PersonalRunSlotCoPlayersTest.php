<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;

/**
 * Story 16.17: managing who else plays a slot of a private run.
 *
 * The run owner decides - they are the person who knows how the party is organised, and letting a
 * player attach themselves to a slot would let anyone claim someone else's game.
 */
final class PersonalRunSlotCoPlayersTest extends FunctionalTestCase
{
    private const string SLOT_ID = 'game-slot-1';

    public function testOwnerAddsACoPlayerToASlot(): void
    {
        [$owner, $member, $mate, $run] = $this->party();

        $this->loginAs($owner);
        $this->client->jsonRequest(
            'PUT',
            '/api/v1/runs/'.$run->getId().'/slots/'.self::SLOT_ID.'/co-players',
            ['userIds' => [$mate->getId()]],
        );

        self::assertResponseIsSuccessful();
        $coPlayers = $this->coPlayersOfResponse();
        self::assertCount(1, $coPlayers);
        self::assertSame($mate->getId(), $coPlayers[0]['userId']);

        // The slot's own view shows them to every participant: playing together is not private.
        $this->loginAs($member);
        $this->client->jsonRequest('GET', '/api/v1/runs/'.$run->getId().'/participants/'.$member->getId().'/game-selection');
        self::assertResponseIsSuccessful();

        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $slots = $data['slots'] ?? null;
        self::assertIsArray($slots);
        $slot = $slots[0];
        self::assertIsArray($slot);
        self::assertIsArray($slot['coPlayers']);
        self::assertCount(1, $slot['coPlayers']);
    }

    /** The roster is replaced wholesale, so an empty list is how someone is removed. */
    public function testAnEmptyListRemovesEveryone(): void
    {
        [$owner, , $mate, $run] = $this->party();

        $this->loginAs($owner);
        $this->client->jsonRequest(
            'PUT',
            '/api/v1/runs/'.$run->getId().'/slots/'.self::SLOT_ID.'/co-players',
            ['userIds' => [$mate->getId()]],
        );
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest(
            'PUT',
            '/api/v1/runs/'.$run->getId().'/slots/'.self::SLOT_ID.'/co-players',
            ['userIds' => []],
        );

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->coPlayersOfResponse());
    }

    /** Repeating the call changes nothing: the caller sends a list, never a diff. */
    public function testTheCallIsIdempotent(): void
    {
        [$owner, , $mate, $run] = $this->party();

        $this->loginAs($owner);
        foreach ([1, 2] as $ignored) {
            $this->client->jsonRequest(
                'PUT',
                '/api/v1/runs/'.$run->getId().'/slots/'.self::SLOT_ID.'/co-players',
                ['userIds' => [$mate->getId()]],
            );
            self::assertResponseIsSuccessful();
        }

        self::assertCount(1, $this->coPlayersOfResponse());
    }

    public function testAParticipantWhoIsNotTheOwnerIsForbidden(): void
    {
        [, $member, $mate, $run] = $this->party();

        $this->loginAs($member);
        $this->client->jsonRequest(
            'PUT',
            '/api/v1/runs/'.$run->getId().'/slots/'.self::SLOT_ID.'/co-players',
            ['userIds' => [$mate->getId()]],
        );

        self::assertResponseStatusCodeSame(403);
    }

    /** AC 3: someone outside the party has no business on one of its slots. */
    public function testSomeoneOutsideThePartyIsRejected(): void
    {
        [$owner, , , $run] = $this->party();
        $stranger = $this->createUser('stranger@example.org');

        $this->loginAs($owner);
        $this->client->jsonRequest(
            'PUT',
            '/api/v1/runs/'.$run->getId().'/slots/'.self::SLOT_ID.'/co-players',
            ['userIds' => [$stranger->getId()]],
        );

        self::assertResponseStatusCodeSame(422);
    }

    /** AC 2: one cannot co-play a slot one already owns - it would count its checks twice. */
    public function testTheSlotOwnerIsRejected(): void
    {
        [$owner, $member, , $run] = $this->party();

        $this->loginAs($owner);
        $this->client->jsonRequest(
            'PUT',
            '/api/v1/runs/'.$run->getId().'/slots/'.self::SLOT_ID.'/co-players',
            ['userIds' => [$member->getId()]],
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testAnUnknownSlotIsRejected(): void
    {
        [$owner, , $mate, $run] = $this->party();

        $this->loginAs($owner);
        $this->client->jsonRequest(
            'PUT',
            '/api/v1/runs/'.$run->getId().'/slots/nope/co-players',
            ['userIds' => [$mate->getId()]],
        );

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * The member, the mate and the owner of a run where `member` declared one slot.
     *
     * @return array{0: \App\Identity\Domain\Entity\User, 1: \App\Identity\Domain\Entity\User, 2: \App\Identity\Domain\Entity\User, 3: Run}
     */
    private function party(): array
    {
        $owner = $this->createUser('owner@example.org');
        $member = $this->createUser('member@example.org');
        $mate = $this->createUser('mate@example.org');
        $game = $this->createGame('Minecraft', 'minecraft');

        $run = Run::create($owner->getId(), 'My Run', new \DateTimeImmutable('2026-08-25T10:00:00+00:00'));
        $this->entityManager->persist($run);

        $participant = RunParticipant::create($run->getId(), $member->getId(), new \DateTimeImmutable('2026-08-25T10:00:00+00:00'));
        $participant->replaceSlots([['slotId' => self::SLOT_ID, 'gameId' => $game->getId()]]);
        $this->entityManager->persist($participant);

        $this->entityManager->persist(RunParticipant::create($run->getId(), $mate->getId(), new \DateTimeImmutable('2026-08-25T10:00:00+00:00')));
        $this->entityManager->flush();

        return [$owner, $member, $mate, $run];
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function coPlayersOfResponse(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $coPlayers = $data['coPlayers'] ?? null;
        self::assertIsArray($coPlayers);

        $out = [];
        foreach ($coPlayers as $coPlayer) {
            self::assertIsArray($coPlayer);
            $out[] = $coPlayer;
        }

        return $out;
    }
}
