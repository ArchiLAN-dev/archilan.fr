<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Game;
use App\Identity\Domain\User;
use App\Membership\Domain\Membership;
use App\WeeklyRuns\Domain\WeeklyEntry;
use App\WeeklyRuns\Domain\WeeklyRun;
use App\WeeklyRuns\Domain\WeeklyTemplate;
use Doctrine\ORM\Tools\SchemaTool;

final class WeeklyRunOptInTest extends FunctionalTestCase
{
    private WeeklyRun $run;
    private WeeklyTemplate $template;
    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Membership::class),
            $this->entityManager->getClassMetadata(Game::class),
            $this->entityManager->getClassMetadata(WeeklyTemplate::class),
            $this->entityManager->getClassMetadata(WeeklyRun::class),
            $this->entityManager->getClassMetadata(WeeklyEntry::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $now = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');
        $this->game = $this->createGame('Archipelago', 'archipelago');
        $this->template = $this->createTemplate($this->game->getId(), maxAttempts: null);
        $this->run = $this->createRun($this->template->getId(), WeeklyRun::STATUS_ACTIVE, $now);
    }

    public function testOptInCreatesEntry(): void
    {
        $member = $this->createUser('member@test.com', ['ROLE_USER']);
        $this->createMembership($member->getId());
        $this->loginAs($member);

        $this->client->jsonRequest('POST', '/api/v1/weekly-runs/'.$this->run->getId().'/entries');

        self::assertResponseStatusCodeSame(201);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame($this->run->getId(), $response['data']['weeklyRunId']);
        self::assertSame($member->getId(), $response['data']['userId']);
        self::assertSame(1, $response['data']['attemptNumber']);
    }

    public function testOptInMaxAttemptsBlocksSecondEntry(): void
    {
        $this->entityManager->remove($this->template);
        $this->entityManager->flush();

        $now = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');
        $template = $this->createTemplate($this->game->getId(), maxAttempts: 1);
        $run = $this->createRun($template->getId(), WeeklyRun::STATUS_ACTIVE, $now);

        $member = $this->createUser('member@test.com', ['ROLE_USER']);
        $this->createMembership($member->getId());
        $this->loginAs($member);

        $this->client->jsonRequest('POST', '/api/v1/weekly-runs/'.$run->getId().'/entries');
        self::assertResponseStatusCodeSame(201);

        $this->client->jsonRequest('POST', '/api/v1/weekly-runs/'.$run->getId().'/entries');
        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertSame('max_attempts_reached', $response['error']);
    }

    public function testOptInInactiveRunReturns422(): void
    {
        $now = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');
        $finishedRun = $this->createRun($this->template->getId(), WeeklyRun::STATUS_FINISHED, $now);

        $member = $this->createUser('member@test.com', ['ROLE_USER']);
        $this->createMembership($member->getId());
        $this->loginAs($member);

        $this->client->jsonRequest('POST', '/api/v1/weekly-runs/'.$finishedRun->getId().'/entries');

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertSame('run_not_active', $response['error']);
    }

    public function testOptInUnauthenticatedReturns401(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/weekly-runs/'.$this->run->getId().'/entries');

        self::assertResponseStatusCodeSame(401);
    }

    public function testOptInNonMemberReturns403(): void
    {
        $user = $this->createUser('user@test.com', ['ROLE_USER']);
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/weekly-runs/'.$this->run->getId().'/entries');

        self::assertResponseStatusCodeSame(403);
    }

    public function testWithdrawBeforeLaunchReturns204(): void
    {
        $member = $this->createUser('member@test.com', ['ROLE_USER']);
        $this->createMembership($member->getId());
        $this->loginAs($member);

        $entry = $this->createEntry($this->run->getId(), $member->getId());

        $this->client->jsonRequest('DELETE', '/api/v1/weekly-runs/'.$this->run->getId().'/entries/'.$entry->getId());

        self::assertResponseStatusCodeSame(204);
    }

    public function testWithdrawAfterLaunchReturns422(): void
    {
        $member = $this->createUser('member@test.com', ['ROLE_USER']);
        $this->createMembership($member->getId());
        $this->loginAs($member);

        $now = new \DateTimeImmutable('2026-05-11T10:00:00+00:00');
        $entry = $this->createEntry($this->run->getId(), $member->getId(), launchedWith: 'session-abc', launchedAt: $now);

        $this->client->jsonRequest('DELETE', '/api/v1/weekly-runs/'.$this->run->getId().'/entries/'.$entry->getId());

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertSame('session_already_started', $response['error']);
    }

    public function testWithdrawAnotherUsersEntryReturns403(): void
    {
        $owner = $this->createUser('owner@test.com', ['ROLE_USER']);
        $other = $this->createUser('other@test.com', ['ROLE_USER']);
        $this->createMembership($owner->getId());
        $this->createMembership($other->getId());

        $entry = $this->createEntry($this->run->getId(), $owner->getId());

        $this->loginAs($other);

        $this->client->jsonRequest('DELETE', '/api/v1/weekly-runs/'.$this->run->getId().'/entries/'.$entry->getId());

        self::assertResponseStatusCodeSame(403);
    }

    private function createTemplate(string $gameId, ?int $maxAttempts): WeeklyTemplate
    {
        $now = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');
        $template = new WeeklyTemplate(
            id: bin2hex(random_bytes(8)),
            gameId: $gameId,
            yamlConfig: "name: ArchiLAN\ngame: Archipelago\n",
            name: 'Test Template',
            maxAttempts: $maxAttempts,
            isActive: true,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->entityManager->persist($template);
        $this->entityManager->flush();

        return $template;
    }

    private function createRun(string $templateId, string $status, \DateTimeImmutable $startedAt): WeeklyRun
    {
        $now = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');
        $run = new WeeklyRun(
            id: bin2hex(random_bytes(8)),
            templateId: $templateId,
            weekYear: 2026,
            weekNumber: 20,
            seed: 'archilan-weekly-2026-20',
            status: $status,
            startedAt: $startedAt,
            createdAt: $now,
        );
        if (WeeklyRun::STATUS_FINISHED === $status) {
            $run->finish($now);
        }
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    private function createEntry(
        string $runId,
        string $userId,
        ?string $launchedWith = null,
        ?\DateTimeImmutable $launchedAt = null,
    ): WeeklyEntry {
        $now = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');
        $entry = new WeeklyEntry(
            id: bin2hex(random_bytes(8)),
            weeklyRunId: $runId,
            userId: $userId,
            attemptNumber: 1,
            createdAt: $now,
            updatedAt: $now,
            externalSessionId: $launchedWith,
            launchedAt: $launchedAt,
        );
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function createMembership(string $userId): Membership
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $membership = Membership::create(
            $userId,
            $now,
            new \DateTimeImmutable('2027-05-01T10:00:00+00:00'),
            'admin',
            null,
            null,
            $now,
        );
        $this->entityManager->persist($membership);
        $this->entityManager->flush();

        return $membership;
    }
}
