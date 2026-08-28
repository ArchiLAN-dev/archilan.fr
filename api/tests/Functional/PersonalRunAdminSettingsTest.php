<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\AdminUserActionAudit;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\Sessions\Infrastructure\Double\StubSeedArchiveGateway;
use App\Shared\Infrastructure\Double\NullMinioStorage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Story 16.19 : les réglages d'une partie privée pour un administrateur.
 *
 * Un administrateur pouvait déjà ouvrir la page - le backoffice liste ces parties dans la fiche d'un
 * membre - mais l'onglet Réglages n'existait que pour le propriétaire, et ses trois blocs étaient
 * gardés `isOwnedBy` côté serveur. Il ne pouvait donc que demander au membre de corriger lui-même,
 * y compris quand ce membre est justement celui qui n'y arrive pas.
 *
 * Tout passe par les endpoints : c'est la porte que l'administrateur pousse, pas la couche qu'on a
 * modifiée.
 */
final class PersonalRunAdminSettingsTest extends FunctionalTestCase
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

    // ─── AC 1 : override de configuration ───────────────────────────────────────

    public function testAdminReadsAndWritesTheConfigOverride(): void
    {
        [$owner, $admin, $run] = $this->party();

        $this->loginAs($admin);
        $this->client->jsonRequest('GET', '/api/v1/runs/'.$run->getId().'/config-override');
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('PUT', '/api/v1/runs/'.$run->getId().'/config-override', ['hintCost' => 20]);
        self::assertResponseIsSuccessful();

        self::assertSame(
            [AdminUserActionAudit::ACTION_RUN_CONFIG_OVERRIDE],
            $this->auditActionsFor($owner->getId()),
        );
    }

    public function testAStrangerStillCannotTouchTheConfigOverride(): void
    {
        [, , $run] = $this->party();
        $stranger = $this->createUser('stranger@example.org');

        $this->loginAs($stranger);
        $this->client->jsonRequest('PUT', '/api/v1/runs/'.$run->getId().'/config-override', ['hintCost' => 20]);

        self::assertResponseStatusCodeSame(403);
    }

    // ─── AC 2 : seed importée ───────────────────────────────────────────────────

    public function testAdminImportsASeedAndAssignsItsSlots(): void
    {
        [$owner, $admin, $run] = $this->party();
        StubSeedArchiveGateway::willReturnSlots([
            ['slot' => 1, 'name' => 'Alice_MC', 'game' => 'Minecraft', 'type' => 1],
        ]);

        $this->loginAs($admin);
        $this->upload($run->getId());
        self::assertResponseIsSuccessful();

        $slotId = $this->firstImportedSlotId($run->getId());
        $this->client->jsonRequest(
            'PUT',
            '/api/v1/runs/'.$run->getId().'/imported-slots/'.$slotId,
            ['userIds' => [$owner->getId()]],
        );
        self::assertResponseIsSuccessful();

        self::assertSame(
            [AdminUserActionAudit::ACTION_RUN_SEED_IMPORT, AdminUserActionAudit::ACTION_RUN_SLOT_ASSIGN],
            $this->auditActionsFor($owner->getId()),
        );
    }

    // ─── AC 3 : suppression ─────────────────────────────────────────────────────

    public function testAdminDeletesAnIdleRun(): void
    {
        [$owner, $admin, $run] = $this->party();
        $run->attachSession(bin2hex(random_bytes(16)));
        $run->start(new \DateTimeImmutable());
        $run->markStopped(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->loginAs($admin);
        $this->client->jsonRequest('DELETE', '/api/v1/runs/'.$run->getId());

        self::assertResponseStatusCodeSame(204);
        self::assertSame([AdminUserActionAudit::ACTION_RUN_DELETE], $this->auditActionsFor($owner->getId()));
    }

    // ─── AC 4 : le rôle ouvre une porte, pas les règles ─────────────────────────

    /** Un brouillon n'est ni actif ni terminé : il se supprime, pour l'admin comme pour le propriétaire. */
    public function testAdminDeletesADraftJustAsTheOwnerWould(): void
    {
        [, $admin, $run] = $this->party();

        $this->loginAs($admin);
        $this->client->jsonRequest('DELETE', '/api/v1/runs/'.$run->getId());

        self::assertResponseStatusCodeSame(204);
    }

    /** Une partie active se refuse aux deux : le rôle ouvre une porte, pas les règles d'état. */
    public function testAdminCannotDeleteAnActiveRun(): void
    {
        [, $admin, $run] = $this->party();
        $run->attachSession(bin2hex(random_bytes(16)));
        $run->start(new \DateTimeImmutable());
        $run->markRunning('bridge.local', 38281, new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->loginAs($admin);
        $this->client->jsonRequest('DELETE', '/api/v1/runs/'.$run->getId());

        self::assertResponseStatusCodeSame(422);
    }

    public function testAdminCannotReplaceTheSeedOfALaunchedRun(): void
    {
        [, $admin, $run] = $this->party();
        StubSeedArchiveGateway::willReturnSlots([['slot' => 1, 'name' => 'A', 'game' => 'G', 'type' => 1]]);
        $run->attachSession(bin2hex(random_bytes(16)));
        $run->start(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->loginAs($admin);
        $this->upload($run->getId());

        self::assertResponseStatusCodeSame(422);
    }

    // ─── AC 8 : le propriétaire n'écrit rien ────────────────────────────────────

    public function testTheOwnerActingOnTheirOwnRunLeavesNoTrace(): void
    {
        [$owner, , $run] = $this->party();

        $this->loginAs($owner);
        $this->client->jsonRequest('PUT', '/api/v1/runs/'.$run->getId().'/config-override', ['hintCost' => 20]);
        self::assertResponseIsSuccessful();

        self::assertSame([], $this->auditActionsFor($owner->getId()));
    }

    // ─── AC 5 : ce qui était fermé le reste ─────────────────────────────────────

    public function testTheAdminStillGetsNeitherTheInviteTokenNorTheSessionPassword(): void
    {
        [, $admin, $run] = $this->party();

        $this->loginAs($admin);
        $this->client->jsonRequest('GET', '/api/v1/runs/'.$run->getId());
        self::assertResponseIsSuccessful();

        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertNull($data['inviteToken']);
        self::assertNull($data['adminPassword']);
        self::assertFalse($data['isOwner']);
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    private function auditActionsFor(string $targetUserId): array
    {
        $rows = $this->entityManager->getRepository(AdminUserActionAudit::class)
            ->findBy(['targetUserId' => $targetUserId], ['createdAt' => 'ASC']);

        $out = [];
        foreach ($rows as $row) {
            $out[] = $row->getAction();
        }

        return $out;
    }

    private function upload(string $runId): void
    {
        $path = tempnam(sys_get_temp_dir(), 'seed');
        self::assertIsString($path);
        file_put_contents($path, 'archive-factice-la-passerelle-est-bouchonnee');

        $this->client->request(
            'POST',
            '/api/v1/runs/'.$runId.'/seed',
            [],
            ['file' => new UploadedFile($path, 'AP_1.zip', 'application/zip', null, true)],
        );
    }

    private function firstImportedSlotId(string $runId): string
    {
        $this->client->jsonRequest('GET', '/api/v1/runs/'.$runId);
        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $slots = $data['importedSlots'];
        self::assertIsArray($slots);
        $first = $slots[0];
        self::assertIsArray($first);
        $slotId = $first['slotId'];
        self::assertIsString($slotId);

        return $slotId;
    }

    /**
     * @return array{0: \App\Identity\Domain\Entity\User, 1: \App\Identity\Domain\Entity\User, 2: Run}
     */
    private function party(): array
    {
        $owner = $this->createUser('owner@example.org');
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);

        $run = Run::create($owner->getId(), 'Ma run', new \DateTimeImmutable('2026-08-28T10:00:00+00:00'));
        $this->entityManager->persist($run);
        $this->entityManager->persist(RunParticipant::create($run->getId(), $owner->getId(), new \DateTimeImmutable('2026-08-28T10:00:00+00:00')));
        $this->entityManager->flush();

        return [$owner, $admin, $run];
    }
}
