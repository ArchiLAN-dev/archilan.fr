<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\Community\Application\Query\CommunityLevelQuery;
use App\Community\Application\Query\CommunityPresenceQueryInterface;
use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Application\Support\Notifier;
use App\Community\Domain\Repository\AchievementGrantRepositoryInterface;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Identity\Application\Query\PlayerStatsQueryInterface;
use App\Identity\Domain\Repository\AdminUserActionAuditRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Membership\Application\Query\ActiveMembershipQueryInterface;
use App\PersonalRuns\Application\Port\RunGameAssignmentInterface;
use App\PersonalRuns\Application\Query\MyRunSlotsQueryInterface;
use App\PersonalRuns\Application\Service\PersonalRunDrafts;
use App\PersonalRuns\Application\Support\AdminRunActionTrace;
use App\PersonalRuns\Application\Support\RunJoiner;
use App\PersonalRuns\Application\Support\RunReadinessReader;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunArchiveRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunInvitationRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunNudgeRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Sessions\Domain\Repository\SlotCoPlayerRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * Story 41.39 AC 9: a run whose remaining bronze cannot burn is not deleted - deleting it anyway would leave bronze
 * tied to a run that no longer exists - and the refusal is an answer, not an error.
 */
final class PersonalRunDraftsDeleteBronzeTest extends TestCase
{
    private const string OWNER_ID = 'owner-1';

    public function testARunWhoseBronzeCannotBurnIsNotDeleted(): void
    {
        $run = Run::create(self::OWNER_ID, 'Ma run', new \DateTimeImmutable('2026-10-19T10:00:00+00:00'));
        $bronze = new SpyRunBronze();
        $bronze->burnFailure = new \RuntimeException('ledger unavailable');
        $deleted = false;
        $runs = self::createStub(RunRepositoryInterface::class);
        $runs->method('findById')->willReturn($run);
        $runs->method('delete')->willReturnCallback(static function () use (&$deleted): void {
            $deleted = true;
        });

        $result = $this->drafts($runs, $bronze)->hardDelete($run->getId(), self::OWNER_ID);

        self::assertSame(['found' => true, 'authorized' => true, 'blocked' => true, 'blockReason' => 'bronze_burn_failed'], $result);
        self::assertFalse($deleted);
    }

    public function testARunWhoseBronzeBurnsIsDeleted(): void
    {
        $run = Run::create(self::OWNER_ID, 'Ma run', new \DateTimeImmutable('2026-10-19T10:00:00+00:00'));
        $bronze = new SpyRunBronze();
        $runs = self::createStub(RunRepositoryInterface::class);
        $runs->method('findById')->willReturn($run);

        $result = $this->drafts($runs, $bronze)->hardDelete($run->getId(), self::OWNER_ID);

        self::assertFalse($result['blocked']);
        self::assertSame([$run->getId()], $bronze->burned);
    }

    private function drafts(RunRepositoryInterface $runs, SpyRunBronze $bronze): PersonalRunDrafts
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
            self::createStub(RunArchiveRepositoryInterface::class),
            self::createStub(MyRunSlotsQueryInterface::class),
            new RunJoiner(self::createStub(RunParticipantRepositoryInterface::class), self::createStub(Notifier::class), self::createStub(UserRepositoryInterface::class)),
            self::createStub(RunInvitationRepositoryInterface::class),
            self::createStub(RunNudgeRepositoryInterface::class),
            self::createStub(GameRepositoryInterface::class),
            new RunReadinessReader(self::createStub(SlotCoPlayerRepositoryInterface::class)),
            $bronze,
            new NullLogger(),
        );
    }
}
