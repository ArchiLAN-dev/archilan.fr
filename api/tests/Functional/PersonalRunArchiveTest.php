<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\AdminUserActionAudit;
use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunArchive;
use App\PersonalRuns\Domain\Entity\RunParticipant;

/**
 * Story 16.21: a member puts a personal run away in their own list, and an admin can do it for them. The archive
 * is personal - the other participants keep seeing the run - and reversible.
 */
final class PersonalRunArchiveTest extends FunctionalTestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable('2026-10-05T10:00:00+00:00');
    }

    public function testTheOwnerArchivesAFinishedRunInTheirListOnly(): void
    {
        $owner = $this->createUser('owner@example.org', ['ROLE_USER'], 'Owner');
        $guest = $this->createUser('guest@example.org', ['ROLE_USER'], 'Guest');
        $run = $this->finishedRun($owner, 'Finie');
        $this->entityManager->persist(RunParticipant::create($run->getId(), $guest->getId(), $this->now));
        $this->entityManager->flush();

        $this->loginAs($owner);
        $this->client->jsonRequest('POST', $this->url($run));
        self::assertResponseStatusCodeSame(204);

        self::assertTrue($this->archivedInListOf($owner, $run, 'owned'));
        self::assertFalse($this->archivedInListOf($guest, $run, 'joined'));

        // The run's own page tells each of them where it stands for them.
        self::assertTrue($this->archivedOnRunPageFor($owner, $run));
        self::assertFalse($this->archivedOnRunPageFor($guest, $run));
    }

    private function archivedOnRunPageFor(User $user, Run $run): bool
    {
        $this->loginAs($user);
        $this->client->jsonRequest('GET', sprintf('/api/v1/runs/%s', $run->getId()));
        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return true === $data['archived'];
    }

    public function testAnInvitedParticipantArchivesForThemselvesAndCanBringItBack(): void
    {
        $owner = $this->createUser('owner@example.org', ['ROLE_USER'], 'Owner');
        $guest = $this->createUser('guest@example.org', ['ROLE_USER'], 'Guest');
        $run = Run::create($owner->getId(), 'Brouillon', $this->now);
        $this->entityManager->persist($run);
        $this->entityManager->persist(RunParticipant::create($run->getId(), $guest->getId(), $this->now));
        $this->entityManager->flush();

        $this->loginAs($guest);
        $this->client->jsonRequest('POST', $this->url($run));
        self::assertResponseStatusCodeSame(204);
        // Twice changes nothing.
        $this->client->jsonRequest('POST', $this->url($run));
        self::assertResponseStatusCodeSame(204);
        self::assertTrue($this->archivedInListOf($guest, $run, 'joined'));
        self::assertFalse($this->archivedInListOf($owner, $run, 'owned'));

        $this->loginAs($guest);
        $this->client->jsonRequest('DELETE', $this->url($run));
        self::assertResponseStatusCodeSame(204);
        self::assertFalse($this->archivedInListOf($guest, $run, 'joined'));
    }

    public function testStartingARunTakesItOutOfEveryonesArchives(): void
    {
        $owner = $this->createUser('owner@example.org', ['ROLE_USER'], 'Owner');
        $guest = $this->createUser('guest@example.org', ['ROLE_USER'], 'Guest');
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $run = Run::create($owner->getId(), 'Brouillon', $this->now);
        $mine = RunParticipant::create($run->getId(), $owner->getId(), $this->now);
        $mine->replaceSlots([['slotId' => 's1', 'gameId' => $game->getId()]]);
        $this->entityManager->persist($run);
        $this->entityManager->persist($mine);
        $this->entityManager->persist(RunParticipant::create($run->getId(), $guest->getId(), $this->now));
        $this->entityManager->persist(RunArchive::create($run->getId(), $guest->getId(), $this->now));
        $this->entityManager->flush();

        $this->loginAs($owner);
        $this->client->jsonRequest('POST', sprintf('/api/v1/runs/%s/start', $run->getId()));
        self::assertResponseStatusCodeSame(202);

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(RunArchive::class, ['runId' => $run->getId(), 'userId' => $guest->getId()]));
    }

    public function testDeletingARunTakesItsArchivesWithIt(): void
    {
        $owner = $this->createUser('owner@example.org', ['ROLE_USER'], 'Owner');
        $run = Run::create($owner->getId(), 'Brouillon', $this->now);
        $this->entityManager->persist($run);
        $this->entityManager->persist(RunArchive::create($run->getId(), $owner->getId(), $this->now));
        $this->entityManager->flush();

        $this->loginAs($owner);
        $this->client->jsonRequest('DELETE', sprintf('/api/v1/runs/%s', $run->getId()));
        self::assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(RunArchive::class, ['runId' => $run->getId(), 'userId' => $owner->getId()]));
    }

    public function testALiveRunMustBeStoppedFirst(): void
    {
        $owner = $this->createUser('owner@example.org', ['ROLE_USER'], 'Owner');
        $run = Run::create($owner->getId(), 'En cours', $this->now);
        $run->markRunning('archipelago.test', 38281, $this->now);
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $this->loginAs($owner);
        $this->client->jsonRequest('POST', $this->url($run));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('run_live', $this->errorCode());
    }

    public function testAStrangerToTheRunIsRefused(): void
    {
        $owner = $this->createUser('owner@example.org', ['ROLE_USER'], 'Owner');
        $stranger = $this->createUser('stranger@example.org', ['ROLE_USER'], 'Stranger');
        $run = Run::create($owner->getId(), 'Brouillon', $this->now);
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $this->loginAs($stranger);
        $this->client->jsonRequest('POST', $this->url($run));
        self::assertResponseStatusCodeSame(403);

        $this->client->jsonRequest('POST', '/api/v1/runs/'.str_repeat('0', 32).'/personal-archive');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnAdminArchivesForTheMemberAndTheActionIsTraced(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $member = $this->createUser('member@example.org', ['ROLE_USER'], 'Member');
        $run = $this->finishedRun($member, 'Finie');

        $this->loginAs($admin);
        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/users/%s/runs/%s/archive', $member->getId(), $run->getId()));
        self::assertResponseStatusCodeSame(204);

        // The admin sheet reads the member's list, and the journal says who did it.
        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/users/%s/gaming', $member->getId()));
        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsArray($data['ownedRuns']);
        self::assertIsArray($data['ownedRuns'][0]);
        self::assertTrue($data['ownedRuns'][0]['archived']);

        $audit = $this->entityManager->getRepository(AdminUserActionAudit::class)->findOneBy(['targetUserId' => $member->getId()]);
        self::assertInstanceOf(AdminUserActionAudit::class, $audit);
        self::assertSame(AdminUserActionAudit::ACTION_RUN_ARCHIVE, $audit->getAction());

        $this->client->jsonRequest('DELETE', sprintf('/api/v1/admin/users/%s/runs/%s/archive', $member->getId(), $run->getId()));
        self::assertResponseStatusCodeSame(204);
        self::assertFalse($this->archivedInListOf($member, $run, 'owned'));
    }

    private function finishedRun(User $owner, string $title): Run
    {
        $run = Run::create($owner->getId(), $title, $this->now);
        $run->markRunning('archipelago.test', 38281, $this->now);
        $run->complete($this->now);
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    private function archivedInListOf(User $user, Run $run, string $branch): bool
    {
        $this->loginAs($user);
        $this->client->jsonRequest('GET', '/api/v1/runs/mine');
        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsArray($data[$branch]);
        foreach ($data[$branch] as $listed) {
            self::assertIsArray($listed);
            if ($listed['id'] === $run->getId()) {
                return true === $listed['archived'];
            }
        }
        self::fail(sprintf('Run %s is not in the %s list.', $run->getId(), $branch));
    }

    private function errorCode(): mixed
    {
        $error = $this->decodedJsonResponse()['error'] ?? null;
        self::assertIsArray($error);

        return $error['code'] ?? null;
    }

    private function url(Run $run): string
    {
        return sprintf('/api/v1/runs/%s/personal-archive', $run->getId());
    }
}
