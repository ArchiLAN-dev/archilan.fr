<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;

/**
 * Story 16.27: the owner of a private run makes participants co-organisers, who pilot the run with them
 * (launch, stop, configuration) but get neither the spoiler, nor the end of the run, nor its participants.
 */
final class RunCoOwnerTest extends FunctionalTestCase
{
    private User $owner;
    private User $player;
    private Run $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->createUser('owner@example.org');
        $this->player = $this->createUser('player@example.org');
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $now = new \DateTimeImmutable('2026-11-22T10:00:00+00:00');
        $this->run = Run::create($this->owner->getId(), 'Partie de test', $now);
        $this->run->configureGames([['gameId' => $game->getId()]], $now);
        $this->entityManager->persist($this->run);
        $ownerParticipant = RunParticipant::create($this->run->getId(), $this->owner->getId(), $now);
        $ownerParticipant->replaceSlots([['slotId' => bin2hex(random_bytes(8)), 'gameId' => $game->getId()]]);
        $this->entityManager->persist($ownerParticipant);
        $this->entityManager->persist(RunParticipant::create($this->run->getId(), $this->player->getId(), $now));
        $this->entityManager->flush();
    }

    public function testTheOwnerNamesAndRevokesACoOwner(): void
    {
        $this->loginAs($this->owner);

        $this->setCoOwner($this->player, true);
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->participant($this->player)->isCoOwner());

        $this->client->request('GET', '/api/v1/runs/'.$this->run->getId());
        self::assertResponseStatusCodeSame(200);
        $participants = $this->data()['participants'] ?? null;
        self::assertIsArray($participants);
        $coOwners = array_column(array_filter($participants, static fn (mixed $p): bool => is_array($p) && true === ($p['coOwner'] ?? null)), 'userId');
        self::assertSame([$this->player->getId()], $coOwners);

        $this->setCoOwner($this->player, false);
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->participant($this->player)->isCoOwner());
    }

    public function testOnlyTheOwnerNamesACoOwner(): void
    {
        $this->makeCoOwner();
        $other = $this->createUser('other@example.org');
        $this->entityManager->persist(RunParticipant::create($this->run->getId(), $other->getId(), new \DateTimeImmutable()));
        $this->entityManager->flush();
        $this->loginAs($this->player);

        $this->setCoOwner($other, true);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAStrangerCannotBeNamed(): void
    {
        $stranger = $this->createUser('stranger@example.org');
        $this->loginAs($this->owner);

        $this->setCoOwner($stranger, true);

        self::assertResponseStatusCodeSame(404);
    }

    public function testACoOwnerLaunchesTheRun(): void
    {
        $this->makeCoOwner();
        $this->loginAs($this->player);

        $this->client->request('GET', '/api/v1/runs/'.$this->run->getId());
        $data = $this->data();
        self::assertTrue($data['isCoOwner'] ?? null);
        self::assertTrue($data['canAdminister'] ?? null);
        self::assertFalse($data['isOwner'] ?? null);
        self::assertNull($data['inviteToken'] ?? null);

        $this->client->jsonRequest('POST', '/api/v1/runs/'.$this->run->getId().'/start');

        self::assertResponseStatusCodeSame(202);
        $this->entityManager->clear();
        $run = $this->entityManager->find(Run::class, $this->run->getId());
        self::assertInstanceOf(Run::class, $run);
        self::assertSame(Run::STATUS_STARTING, $run->getStatus());
    }

    public function testACoOwnerSetsTheConfigOverride(): void
    {
        $this->makeCoOwner();
        $this->loginAs($this->player);

        $this->client->jsonRequest('PUT', '/api/v1/runs/'.$this->run->getId().'/config-override', ['releaseMode' => 'goal']);

        self::assertResponseStatusCodeSame(200);
    }

    public function testACoOwnerKeepsNeitherSpoilerNorEndNorParticipants(): void
    {
        $this->makeCoOwner();
        new \ReflectionProperty(Run::class, 'status')->setValue($this->run, Run::STATUS_ACTIVE);
        $this->entityManager->flush();
        $this->loginAs($this->player);

        $this->client->request('GET', '/api/v1/runs/'.$this->run->getId().'/spoiler');
        self::assertResponseStatusCodeSame(403);

        $this->client->jsonRequest('POST', '/api/v1/runs/'.$this->run->getId().'/finish');
        self::assertResponseStatusCodeSame(403);

        $this->client->jsonRequest('POST', '/api/v1/runs/'.$this->run->getId().'/stop');
        self::assertResponseStatusCodeSame(202);
    }

    public function testAPlainParticipantStillCannotLaunch(): void
    {
        $this->loginAs($this->player);

        $this->client->jsonRequest('POST', '/api/v1/runs/'.$this->run->getId().'/start');

        self::assertResponseStatusCodeSame(403);
    }

    private function makeCoOwner(): void
    {
        $this->participant($this->player)->makeCoOwner(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    private function setCoOwner(User $user, bool $coOwner): void
    {
        $this->client->jsonRequest('PUT', '/api/v1/runs/'.$this->run->getId().'/participants/'.$user->getId().'/co-owner', ['coOwner' => $coOwner]);
    }

    private function participant(User $user): RunParticipant
    {
        $participant = $this->entityManager->find(RunParticipant::class, ['runId' => $this->run->getId(), 'userId' => $user->getId()]);
        self::assertInstanceOf(RunParticipant::class, $participant);
        $this->entityManager->refresh($participant);

        return $participant;
    }

    /** @return array<mixed> */
    private function data(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent() ?: '', true);
        self::assertIsArray($decoded);
        $data = $decoded['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }
}
