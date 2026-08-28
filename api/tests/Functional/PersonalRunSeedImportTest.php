<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Infrastructure\Double\StubSeedArchiveGateway;
use App\Shared\Infrastructure\Double\NullMinioStorage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Story 16.18: creating a party from a seed generated somewhere else.
 *
 * Reading a multidata needs a real Archipelago container, so what these tests pin is everything
 * around it: who may import, what a refusal does to the member, which slots of the archive count as
 * seats, and who may be put on them.
 */
final class PersonalRunSeedImportTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        NullMinioStorage::reset();
        StubSeedArchiveGateway::reset();
    }

    protected function tearDown(): void
    {
        StubSeedArchiveGateway::reset();
        parent::tearDown();
    }

    public function testOwnerImportsASeedAndGetsItsPlayableSlots(): void
    {
        StubSeedArchiveGateway::willReturnSlots([
            // The observer slot our own generation injects: present in some archives, never a seat.
            ['slot' => 1, 'name' => 'Bridge', 'game' => 'Archipelago', 'type' => 0],
            ['slot' => 2, 'name' => 'Alice_MC', 'game' => 'Minecraft', 'type' => 1],
            ['slot' => 3, 'name' => 'Bob_HK', 'game' => 'Hollow Knight', 'type' => 1],
            // An item-link group is a pseudo slot, not a person.
            ['slot' => 4, 'name' => 'Links', 'game' => 'Minecraft', 'type' => 2],
        ]);

        [$owner, , $run] = $this->party();
        $this->loginAs($owner);

        $this->upload($run->getId());

        self::assertResponseIsSuccessful();
        $slots = $this->slotsOfResponse();
        self::assertCount(2, $slots);
        self::assertSame('Alice_MC', $slots[0]['name']);
        self::assertSame('Bob_HK', $slots[1]['name']);
        self::assertSame([], $slots[0]['assignedUserIds']);
    }

    /** The archive is refused before anything is written, with the reason. */
    public function testAnUnreadableArchiveIsRefused(): void
    {
        StubSeedArchiveGateway::willRefuse('no .archipelago file in the archive');

        [$owner, , $run] = $this->party();
        $this->loginAs($owner);

        $this->upload($run->getId());

        self::assertResponseStatusCodeSame(422);

        // Nothing was stored, and the run is still a normal one.
        $this->client->jsonRequest('GET', '/api/v1/runs/'.$run->getId());
        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload()['importedSeed']);
    }

    /** An archive whose only slots are spectators or groups holds no party. */
    public function testAnArchiveWithoutAPlayableSlotIsRefused(): void
    {
        StubSeedArchiveGateway::willReturnSlots([
            ['slot' => 1, 'name' => 'Bridge', 'game' => 'Archipelago', 'type' => 0],
        ]);

        [$owner, , $run] = $this->party();
        $this->loginAs($owner);

        $this->upload($run->getId());

        self::assertResponseStatusCodeSame(422);
    }

    public function testAParticipantCannotImport(): void
    {
        StubSeedArchiveGateway::willReturnSlots([['slot' => 1, 'name' => 'A', 'game' => 'G', 'type' => 1]]);

        [, $member, $run] = $this->party();
        $this->loginAs($member);

        $this->upload($run->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheRunSaysItHostsAnImportedSeed(): void
    {
        [$owner, , $run] = $this->importedRun();
        $this->loginAs($owner);

        $this->client->jsonRequest('GET', '/api/v1/runs/'.$run->getId());

        self::assertResponseIsSuccessful();
        $payload = $this->payload();
        self::assertTrue($payload['importedSeed']);
        self::assertIsArray($payload['importedSlots']);
        self::assertCount(2, $payload['importedSlots']);
    }

    public function testOwnerAssignsASlotToSeveralParticipants(): void
    {
        [$owner, $member, $run] = $this->importedRun();
        $this->loginAs($owner);

        $slotId = $this->firstSlotId($run->getId());
        $this->client->jsonRequest(
            'PUT',
            '/api/v1/runs/'.$run->getId().'/imported-slots/'.$slotId,
            ['userIds' => [$member->getId(), $owner->getId()]],
        );

        self::assertResponseIsSuccessful();
        $slots = $this->slotsOfResponse();
        self::assertSame([$member->getId(), $owner->getId()], $slots[0]['assignedUserIds']);
    }

    /** A seed can hold worlds nobody in this party plays: an empty assignment is legitimate. */
    public function testASlotMayBeLeftUnassigned(): void
    {
        [$owner, $member, $run] = $this->importedRun();
        $this->loginAs($owner);

        $slotId = $this->firstSlotId($run->getId());
        $this->client->jsonRequest('PUT', '/api/v1/runs/'.$run->getId().'/imported-slots/'.$slotId, ['userIds' => [$member->getId()]]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('PUT', '/api/v1/runs/'.$run->getId().'/imported-slots/'.$slotId, ['userIds' => []]);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->slotsOfResponse()[0]['assignedUserIds']);
    }

    public function testSomeoneOutsideThePartyCannotBeAssigned(): void
    {
        [$owner, , $run] = $this->importedRun();
        $stranger = $this->createUser('stranger@example.org');
        $this->loginAs($owner);

        $slotId = $this->firstSlotId($run->getId());
        $this->client->jsonRequest('PUT', '/api/v1/runs/'.$run->getId().'/imported-slots/'.$slotId, ['userIds' => [$stranger->getId()]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testAParticipantCannotAssign(): void
    {
        [$owner, $member, $run] = $this->importedRun();
        $slotId = $this->firstSlotId($run->getId());

        $this->loginAs($member);
        $this->client->jsonRequest('PUT', '/api/v1/runs/'.$run->getId().'/imported-slots/'.$slotId, ['userIds' => [$member->getId()]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnUnknownSlotIsRejected(): void
    {
        [$owner, $member, $run] = $this->importedRun();
        $this->loginAs($owner);

        $this->client->jsonRequest('PUT', '/api/v1/runs/'.$run->getId().'/imported-slots/nope', ['userIds' => [$member->getId()]]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * AC 12: reachability re-generates the world and needs the yamls an output archive does not
     * carry. The endpoint has to say so, not fall into a generation error - and spend no container
     * finding out.
     */
    public function testReachabilityIsRefusedOnAnImportedRun(): void
    {
        [$owner, , $run] = $this->importedRun();

        $session = $this->runningSessionFor($run);

        $this->loginAs($owner);
        $this->client->jsonRequest('GET', '/api/v1/sessions/'.$session->getId().'/slots/2/reachable');

        self::assertResponseStatusCodeSame(409);
        $error = $this->decodedJsonResponse()['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame('detailed_progression_unavailable', $error['code']);
    }

    /** Same refusal on the realtime token, so the page never subscribes to a topic nobody feeds. */
    public function testTheReachabilityTokenIsRefusedOnAnImportedRun(): void
    {
        [$owner, , $run] = $this->importedRun();

        $session = $this->runningSessionFor($run);

        $this->loginAs($owner);
        $this->client->jsonRequest('GET', '/api/v1/sessions/'.$session->getId().'/slots/2/reachable-token');

        self::assertResponseStatusCodeSame(409);
    }

    /** A run generated here keeps its reachability: the refusal is about the imported case only. */
    public function testReachabilityIsNotRefusedOnAGeneratedRun(): void
    {
        [$owner, , $run] = $this->party();

        $session = $this->runningSessionFor($run);

        $this->loginAs($owner);
        $this->client->jsonRequest('GET', '/api/v1/sessions/'.$session->getId().'/slots/2/reachable-token');

        // Whatever the outcome, it is not the imported-seed refusal.
        $error = $this->decodedJsonResponse()['error'] ?? null;
        $code = is_array($error) ? ($error['code'] ?? null) : null;
        self::assertNotSame('detailed_progression_unavailable', $code);
    }

    /**
     * AC 9, par le chemin que le joueur emprunte vraiment.
     *
     * Le lancement exigeait qu'un participant ait déclaré au moins un jeu. Sur une seed importée
     * personne n'en déclare - les slots viennent de l'archive - donc le bouton restait mort et
     * l'API refusait avec `games_required`. La story avait testé le handler de lancement isolément,
     * pas la porte qui y mène.
     */
    public function testAnImportedRunStartsWithoutAnyoneDeclaringAGame(): void
    {
        [$owner, , $run] = $this->importedRun();
        $this->loginAs($owner);

        $this->client->jsonRequest('POST', '/api/v1/runs/'.$run->getId().'/start');

        self::assertResponseStatusCodeSame(202);
    }

    /** Une partie ordinaire garde son garde-fou : sans jeu déclaré, elle ne part pas. */
    public function testAnOrdinaryRunStillNeedsADeclaredGame(): void
    {
        [$owner, , $run] = $this->party();
        $this->loginAs($owner);

        $this->client->jsonRequest('POST', '/api/v1/runs/'.$run->getId().'/start');

        self::assertResponseStatusCodeSame(422);
        $error = $this->decodedJsonResponse()['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame('games_required', $error['code']);
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function runningSessionFor(Run $run): Session
    {
        $now = new \DateTimeImmutable('2026-08-26T10:00:00+00:00');
        $session = Session::create(bin2hex(random_bytes(16)), $run->getId(), $now);
        $session->transition(Session::STATUS_VALIDATING, $now);
        $session->transition(Session::STATUS_READY, $now);
        $session->transition(Session::STATUS_GENERATING, $now);
        $session->transition(Session::STATUS_GENERATED, $now);
        $session->transition(Session::STATUS_LAUNCHING, $now);
        $session->transition(Session::STATUS_RUNNING, $now, 'bridge.local', 38281, 'secret', 5000);
        $this->entityManager->persist($session);
        $run->attachSession($session->getId());
        $this->entityManager->flush();

        return $session;
    }

    private function upload(string $runId): void
    {
        $path = tempnam(sys_get_temp_dir(), 'seed');
        self::assertIsString($path);
        file_put_contents($path, 'not-really-a-zip-the-gateway-is-stubbed');

        $this->client->request(
            'POST',
            '/api/v1/runs/'.$runId.'/seed',
            [],
            ['file' => new UploadedFile($path, 'AP_123.zip', 'application/zip', null, true)],
        );
    }

    /**
     * A run with an owner and one other participant, plus a two-slot imported archive.
     *
     * @return array{0: \App\Identity\Domain\Entity\User, 1: \App\Identity\Domain\Entity\User, 2: Run}
     */
    private function importedRun(): array
    {
        StubSeedArchiveGateway::willReturnSlots([
            ['slot' => 1, 'name' => 'Alice_MC', 'game' => 'Minecraft', 'type' => 1],
            ['slot' => 2, 'name' => 'Bob_HK', 'game' => 'Hollow Knight', 'type' => 1],
        ]);

        [$owner, $member, $run] = $this->party();
        $this->loginAs($owner);
        $this->upload($run->getId());
        self::assertResponseIsSuccessful();

        return [$owner, $member, $run];
    }

    /**
     * @return array{0: \App\Identity\Domain\Entity\User, 1: \App\Identity\Domain\Entity\User, 2: Run}
     */
    private function party(): array
    {
        $owner = $this->createUser('owner@example.org');
        $member = $this->createUser('member@example.org');

        $run = Run::create($owner->getId(), 'Ma run importée', new \DateTimeImmutable('2026-08-26T10:00:00+00:00'));
        $this->entityManager->persist($run);
        $this->entityManager->persist(RunParticipant::create($run->getId(), $owner->getId(), new \DateTimeImmutable('2026-08-26T10:00:00+00:00')));
        $this->entityManager->persist(RunParticipant::create($run->getId(), $member->getId(), new \DateTimeImmutable('2026-08-26T10:00:00+00:00')));
        $this->entityManager->flush();

        return [$owner, $member, $run];
    }

    private function firstSlotId(string $runId): string
    {
        $this->client->jsonRequest('GET', '/api/v1/runs/'.$runId);
        self::assertResponseIsSuccessful();
        $slots = $this->payload()['importedSlots'];
        self::assertIsArray($slots);
        $first = $slots[0];
        self::assertIsArray($first);
        $slotId = $first['slotId'];
        self::assertIsString($slotId);

        return $slotId;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function payload(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @return list<array{name: string, assignedUserIds: list<string>}>
     */
    private function slotsOfResponse(): array
    {
        $slots = $this->payload()['slots'] ?? null;
        self::assertIsArray($slots);

        $out = [];
        foreach ($slots as $slot) {
            self::assertIsArray($slot);
            $name = $slot['name'] ?? '';
            $assigned = $slot['assignedUserIds'] ?? [];
            self::assertIsString($name);
            self::assertIsArray($assigned);
            $ids = [];
            foreach ($assigned as $id) {
                self::assertIsString($id);
                $ids[] = $id;
            }
            $out[] = ['name' => $name, 'assignedUserIds' => $ids];
        }

        return $out;
    }
}
