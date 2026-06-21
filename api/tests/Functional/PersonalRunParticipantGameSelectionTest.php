<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunParticipant;

final class PersonalRunParticipantGameSelectionTest extends FunctionalTestCase
{
    public function testOwnerCanReadParticipantSlotsAndYaml(): void
    {
        $owner = $this->createUser('owner@example.org');
        $member = $this->createUser('member@example.org', slug: 'member');
        $game = $this->createGame('Super Metroid', 'super-metroid');

        $run = Run::create($owner->getId(), 'My Run', new \DateTimeImmutable('2026-05-12T10:00:00+00:00'));
        $this->entityManager->persist($run);

        $this->persistParticipantWithYaml($run->getId(), $member->getId(), $game->getId(), "name: Member\n");

        $this->loginAs($owner);
        $this->client->jsonRequest('GET', '/api/v1/runs/'.$run->getId().'/participants/'.$member->getId().'/game-selection');

        self::assertResponseIsSuccessful();

        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $identity = $data['participant'] ?? null;
        self::assertIsArray($identity);
        self::assertSame($member->getId(), $identity['userId']);
        self::assertSame('Test User', $identity['displayName']);

        $slot = $this->firstSlot();
        self::assertSame('Super Metroid', $slot['gameName']);
        self::assertSame('super-metroid', $slot['gameSlug']);
        self::assertSame("name: Member\n", $slot['playerYaml']);
    }

    public function testCoParticipantCanReadAnotherParticipantSlots(): void
    {
        $owner = $this->createUser('owner@example.org');
        $member = $this->createUser('member@example.org');
        $coMember = $this->createUser('co@example.org');
        $game = $this->createGame('Super Metroid', 'super-metroid');

        $run = Run::create($owner->getId(), 'My Run', new \DateTimeImmutable('2026-05-12T10:00:00+00:00'));
        $this->entityManager->persist($run);

        $this->persistParticipantWithYaml($run->getId(), $member->getId(), $game->getId(), "name: Member\n");
        $this->persistParticipantWithYaml($run->getId(), $coMember->getId(), $game->getId(), "name: Co\n");

        $this->loginAs($coMember);
        $this->client->jsonRequest('GET', '/api/v1/runs/'.$run->getId().'/participants/'.$member->getId().'/game-selection');

        self::assertResponseIsSuccessful();
        $slot = $this->firstSlot();
        self::assertSame("name: Member\n", $slot['playerYaml']);
    }

    public function testStrangerIsForbidden(): void
    {
        $owner = $this->createUser('owner@example.org');
        $member = $this->createUser('member@example.org');
        $stranger = $this->createUser('stranger@example.org');
        $game = $this->createGame('Super Metroid', 'super-metroid');

        $run = Run::create($owner->getId(), 'My Run', new \DateTimeImmutable('2026-05-12T10:00:00+00:00'));
        $this->entityManager->persist($run);

        $this->persistParticipantWithYaml($run->getId(), $member->getId(), $game->getId(), "name: Member\n");

        $this->loginAs($stranger);
        $this->client->jsonRequest('GET', '/api/v1/runs/'.$run->getId().'/participants/'.$member->getId().'/game-selection');

        self::assertResponseStatusCodeSame(403);
    }

    public function testUnknownParticipantIsNotFound(): void
    {
        $owner = $this->createUser('owner@example.org');

        $run = Run::create($owner->getId(), 'My Run', new \DateTimeImmutable('2026-05-12T10:00:00+00:00'));
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $this->loginAs($owner);
        $this->client->jsonRequest('GET', '/api/v1/runs/'.$run->getId().'/participants/'.bin2hex(random_bytes(16)).'/game-selection');

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnauthenticatedIsRejected(): void
    {
        $owner = $this->createUser('owner@example.org');
        $member = $this->createUser('member@example.org');

        $run = Run::create($owner->getId(), 'My Run', new \DateTimeImmutable('2026-05-12T10:00:00+00:00'));
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $this->client->jsonRequest('GET', '/api/v1/runs/'.$run->getId().'/participants/'.$member->getId().'/game-selection');

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @return array<mixed>
     */
    private function firstSlot(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $slots = $data['slots'] ?? null;
        self::assertIsArray($slots);
        self::assertCount(1, $slots);
        $slot = $slots[0] ?? null;
        self::assertIsArray($slot);

        return $slot;
    }

    private function persistParticipantWithYaml(string $runId, string $userId, string $gameId, string $yaml): void
    {
        $slotId = bin2hex(random_bytes(8));
        $participant = RunParticipant::create($runId, $userId, new \DateTimeImmutable('2026-05-12T10:00:00+00:00'));
        $participant->replaceSlots([['slotId' => $slotId, 'gameId' => $gameId]]);
        $participant->setSlotPlayerYaml($slotId, $yaml, 'hash');
        $this->entityManager->persist($participant);
        $this->entityManager->flush();
    }
}
