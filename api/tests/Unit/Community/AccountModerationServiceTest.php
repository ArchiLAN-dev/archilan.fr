<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Message\PostModerationActionToForumJob;
use App\Community\Application\Port\MemberModerationGatewayInterface;
use App\Community\Application\Query\CommunityAdminIdsQueryInterface;
use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Application\Service\AccountModerationService;
use App\Community\Application\Support\Notifier;
use App\Community\Domain\Entity\ModerationAction;
use App\Community\Domain\Repository\ContentReportRepositoryInterface;
use App\Community\Domain\Repository\ModerationActionRepositoryInterface;
use App\Tests\Unit\Payments\RecordingMessageBus;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class AccountModerationServiceTest extends TestCase
{
    public function testBanRollsBackWhenTheAuditWriteFails(): void
    {
        $gateway = self::createStub(MemberModerationGatewayInterface::class);
        $gateway->method('ban')->willReturn(true); // Identity state changed within the transaction…

        $actions = $this->createMock(ModerationActionRepositoryInterface::class);
        $actions->expects(self::once())->method('beginTransaction');
        $actions->method('save')->willThrowException(new \RuntimeException('db down')); // …then the audit write fails
        $actions->expects(self::once())->method('rollBack');
        $actions->expects(self::never())->method('commit');

        $admins = self::createStub(CommunityAdminIdsQueryInterface::class);
        $admins->method('adminUserIds')->willReturn([]);

        $service = new AccountModerationService(
            $gateway,
            $actions,
            self::createStub(ContentReportRepositoryInterface::class),
            self::createStub(CommunityUserDirectoryQueryInterface::class),
            $admins,
            self::createStub(Notifier::class),
            new MockClock(),
            new RecordingMessageBus(),
            new NullLogger(),
        );

        // The whole operation aborts and rolls back rather than leaving a banned user with no audit trail.
        $this->expectException(\RuntimeException::class);
        $service->ban('admin', 'target', 'spam');
    }

    public function testSelfAndAdminTargetsAreRefusedWithoutOpeningATransaction(): void
    {
        $gateway = self::createStub(MemberModerationGatewayInterface::class);
        $admins = self::createStub(CommunityAdminIdsQueryInterface::class);
        $admins->method('adminUserIds')->willReturn(['target-admin']);

        $actions = $this->createMock(ModerationActionRepositoryInterface::class);
        $actions->expects(self::never())->method('beginTransaction');

        $service = new AccountModerationService(
            $gateway,
            $actions,
            self::createStub(ContentReportRepositoryInterface::class),
            self::createStub(CommunityUserDirectoryQueryInterface::class),
            $admins,
            self::createStub(Notifier::class),
            new MockClock(),
            new RecordingMessageBus(),
            new NullLogger(),
        );

        self::assertSame('forbidden', $service->ban('admin', 'admin', 'self'));
        self::assertSame('forbidden', $service->ban('admin', 'target-admin', 'other admin'));
    }

    public function testEverySuccessfulSanctionIsSentToTheStaffForumAfterItsCommit(): void
    {
        // Story 39.1: the forum post is a side effect after the commit - never before, never on a refusal.
        $gateway = self::createStub(MemberModerationGatewayInterface::class);
        $gateway->method('ban')->willReturn(true);
        $gateway->method('suspendUntil')->willReturn(true);
        $gateway->method('lift')->willReturn(true);
        $admins = self::createStub(CommunityAdminIdsQueryInterface::class);
        $admins->method('adminUserIds')->willReturn([]);
        $directory = self::createStub(CommunityUserDirectoryQueryInterface::class);
        $directory->method('cards')->willReturn(['target' => ['userId' => 'target', 'slug' => 't', 'displayName' => 'T', 'avatarUrl' => null, 'avatarAnimatedUrl' => null, 'avatarFraming' => null]]);
        $saved = [];
        $actions = self::createStub(ModerationActionRepositoryInterface::class);
        $actions->method('save')->willReturnCallback(static function (ModerationAction $action) use (&$saved): void {
            $saved[] = $action->getId();
        });
        $bus = new RecordingMessageBus();

        $service = new AccountModerationService($gateway, $actions, self::createStub(ContentReportRepositoryInterface::class), $directory, $admins, self::createStub(Notifier::class), new MockClock('2026-09-27 10:00:00'), $bus, new NullLogger());

        self::assertSame('ok', $service->warn('admin', 'target', 'Spam'));
        self::assertSame('ok', $service->suspend('admin', 'target', new \DateTimeImmutable('2026-10-04 10:00:00'), 'Insultes'));
        self::assertSame('ok', $service->ban('admin', 'target', 'Triche'));
        self::assertSame('ok', $service->lift('admin', 'target', ''));
        self::assertSame('invalid', $service->ban('admin', 'target', '   '));

        self::assertCount(4, $saved);
        self::assertEquals(
            array_map(static fn (string $id): PostModerationActionToForumJob => new PostModerationActionToForumJob($id), $saved),
            $bus->messagesOf(PostModerationActionToForumJob::class),
            'one job per recorded sanction, carrying its id',
        );
    }

    public function testANoteIsRecordedAndSentToTheForumWithoutTellingTheMember(): void
    {
        // Story 39.10: a trace for the staff only.
        $admins = self::createStub(CommunityAdminIdsQueryInterface::class);
        $admins->method('adminUserIds')->willReturn(['target-admin']);
        $directory = self::createStub(CommunityUserDirectoryQueryInterface::class);
        $directory->method('cards')->willReturn(['target' => ['userId' => 'target', 'slug' => 't', 'displayName' => 'T', 'avatarUrl' => null, 'avatarAnimatedUrl' => null, 'avatarFraming' => null]]);
        $saved = [];
        $actions = self::createStub(ModerationActionRepositoryInterface::class);
        $actions->method('save')->willReturnCallback(static function (ModerationAction $action) use (&$saved): void {
            $saved[] = $action;
        });
        $notifier = $this->createMock(Notifier::class);
        $notifier->expects(self::never())->method('notify');
        $bus = new RecordingMessageBus();

        $service = new AccountModerationService(self::createStub(MemberModerationGatewayInterface::class), $actions, self::createStub(ContentReportRepositoryInterface::class), $directory, $admins, $notifier, new MockClock('2026-09-28 20:00:00'), $bus, new NullLogger());

        self::assertSame('ok', $service->note('admin', 'target', 'Rappelé à l\'ordre en vocal'));
        self::assertCount(1, $saved);
        self::assertSame(ModerationAction::ACTION_NOTE, $saved[0]->getAction());
        self::assertSame('Rappelé à l\'ordre en vocal', $saved[0]->getReason());
        self::assertEquals([new PostModerationActionToForumJob($saved[0]->getId())], $bus->messages, 'the staff forum gets it');

        self::assertSame('invalid', $service->note('admin', 'target', '  '));
        self::assertSame('forbidden', $service->note('admin', 'admin', 'self'));
        self::assertSame('forbidden', $service->note('admin', 'target-admin', 'an admin'));
    }
}
