<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\Community\Application\CommunityUserDirectoryQueryInterface;
use App\Identity\Domain\UserRepositoryInterface;
use App\PersonalRuns\Application\PersonalRunDrafts;
use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\RunRepositoryInterface;
use App\Sessions\Domain\SessionRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class PersonalRunDraftsListMineTest extends TestCase
{
    private function drafts(RunRepositoryInterface $runs): PersonalRunDrafts
    {
        return new PersonalRunDrafts(
            $runs,
            $this->createStub(RunParticipantRepositoryInterface::class),
            $this->createStub(UserRepositoryInterface::class),
            $this->createStub(SessionRepositoryInterface::class),
            $this->createStub(CommunityUserDirectoryQueryInterface::class),
            'https://archilan.test',
        );
    }

    public function testListMineSplitsOwnedAndJoined(): void
    {
        $now = new \DateTimeImmutable('2026-06-12T10:00:00+00:00');
        $ownedRun = Run::create('user-1', 'Ma partie', $now);
        $joinedRun = Run::create('owner-2', 'Partie de Bob', $now);

        $runs = $this->createStub(RunRepositoryInterface::class);
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

        $runs = $this->createStub(RunRepositoryInterface::class);
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

        $runs = $this->createStub(RunRepositoryInterface::class);
        $runs->method('findByOwnerId')->willReturn([]);
        $runs->method('findJoinedByUserId')->willReturn([$joinedRun]);

        $joined = $this->drafts($runs)->listMine('user-1')['joined'][0];

        self::assertFalse($joined['isOwner']);
        self::assertNull($joined['inviteToken']);
        self::assertNull($joined['adminPassword']);
    }
}
