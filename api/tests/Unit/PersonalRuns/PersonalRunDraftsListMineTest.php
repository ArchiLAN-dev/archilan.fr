<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\Community\Application\Query\CommunityLevelQuery;
use App\Community\Application\Query\CommunityPresenceQueryInterface;
use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Domain\Repository\AchievementGrantRepositoryInterface;
use App\Identity\Application\Query\PlayerStatsQueryInterface;
use App\Identity\Domain\Repository\AdminUserActionAuditRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Membership\Application\Query\ActiveMembershipQueryInterface;
use App\PersonalRuns\Application\Port\RunGameAssignmentInterface;
use App\PersonalRuns\Application\Service\PersonalRunDrafts;
use App\PersonalRuns\Application\Support\AdminRunActionTrace;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunArchiveRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class PersonalRunDraftsListMineTest extends TestCase
{
    private function drafts(RunRepositoryInterface $runs, ?RunArchiveRepositoryInterface $archives = null): PersonalRunDrafts
    {
        return new PersonalRunDrafts(
            $runs,
            self::createStub(RunParticipantRepositoryInterface::class),
            self::createStub(UserRepositoryInterface::class),
            self::createStub(SessionRepositoryInterface::class),
            self::createStub(CommunityUserDirectoryQueryInterface::class),
            self::createStub(ActiveMembershipQueryInterface::class),
            new CommunityLevelQuery(
                self::createStub(PlayerStatsQueryInterface::class),
                self::createStub(AchievementGrantRepositoryInterface::class),
            ),
            self::createStub(CommunityPresenceQueryInterface::class),
            self::createStub(RunGameAssignmentInterface::class),
            new MockClock(),
            'https://archilan.test',
            new AdminRunActionTrace(self::createStub(AdminUserActionAuditRepositoryInterface::class), new MockClock()),
            $archives ?? self::createStub(RunArchiveRepositoryInterface::class),
        );
    }

    public function testListMineMarksTheRunsTheCallerArchived(): void
    {
        $now = new \DateTimeImmutable('2026-10-05T10:00:00+00:00');
        $kept = Run::create('user-1', 'Gardée', $now);
        $archived = Run::create('user-1', 'Rangée', $now);
        $joinedArchived = Run::create('owner-2', 'Rejointe rangée', $now);

        $runs = self::createStub(RunRepositoryInterface::class);
        $runs->method('findByOwnerId')->willReturn([$kept, $archived]);
        $runs->method('findJoinedByUserId')->willReturn([$joinedArchived]);
        $archives = self::createStub(RunArchiveRepositoryInterface::class);
        $archives->method('archivedRunIds')->willReturn([$archived->getId(), $joinedArchived->getId()]);

        $result = $this->drafts($runs, $archives)->listMine('user-1');

        self::assertFalse($result['owned'][0]['archived']);
        self::assertTrue($result['owned'][1]['archived']);
        self::assertTrue($result['joined'][0]['archived']);
    }

    public function testListMineSplitsOwnedAndJoined(): void
    {
        $now = new \DateTimeImmutable('2026-06-12T10:00:00+00:00');
        $ownedRun = Run::create('user-1', 'Ma partie', $now);
        $joinedRun = Run::create('owner-2', 'Partie de Bob', $now);

        $runs = self::createStub(RunRepositoryInterface::class);
        $runs->method('findByOwnerId')->willReturn([$ownedRun]);
        $runs->method('findJoinedByUserId')->willReturn([$joinedRun]);

        $result = $this->drafts($runs)->listMine('user-1');

        self::assertCount(1, $result['owned']);
        self::assertCount(1, $result['joined']);
        self::assertSame($ownedRun->getId(), $result['owned'][0]['id']);
        self::assertSame($joinedRun->getId(), $result['joined'][0]['id']);
    }

    public function testListMineOwnedPayloadIsOwnerWithInviteToken(): void
    {
        $now = new \DateTimeImmutable('2026-06-12T10:00:00+00:00');
        $ownedRun = Run::create('user-1', 'Ma partie', $now);

        $runs = self::createStub(RunRepositoryInterface::class);
        $runs->method('findByOwnerId')->willReturn([$ownedRun]);
        $runs->method('findJoinedByUserId')->willReturn([]);

        $owned = $this->drafts($runs)->listMine('user-1')['owned'][0];

        self::assertTrue($owned['isOwner']);
        self::assertSame($ownedRun->getInviteToken(), $owned['inviteToken']);
    }

    public function testListMineJoinedPayloadHidesOwnerSecrets(): void
    {
        $now = new \DateTimeImmutable('2026-06-12T10:00:00+00:00');
        $joinedRun = Run::create('owner-2', 'Partie de Bob', $now);

        $runs = self::createStub(RunRepositoryInterface::class);
        $runs->method('findByOwnerId')->willReturn([]);
        $runs->method('findJoinedByUserId')->willReturn([$joinedRun]);

        $joined = $this->drafts($runs)->listMine('user-1')['joined'][0];

        self::assertFalse($joined['isOwner']);
        self::assertNull($joined['inviteToken']);
        self::assertNull($joined['adminPassword']);
    }
}
