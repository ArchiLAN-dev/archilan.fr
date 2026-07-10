<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Port\MemberModerationGatewayInterface;
use App\Community\Application\Query\CommunityAdminIdsQueryInterface;
use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Application\Service\AccountModerationService;
use App\Community\Application\Support\Notifier;
use App\Community\Domain\Repository\ContentReportRepositoryInterface;
use App\Community\Domain\Repository\ModerationActionRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class AccountModerationServiceTest extends TestCase
{
    public function testBanRollsBackWhenTheAuditWriteFails(): void
    {
        $gateway = $this->createStub(MemberModerationGatewayInterface::class);
        $gateway->method('ban')->willReturn(true); // Identity state changed within the transaction…

        $actions = $this->createMock(ModerationActionRepositoryInterface::class);
        $actions->expects($this->once())->method('beginTransaction');
        $actions->method('save')->willThrowException(new \RuntimeException('db down')); // …then the audit write fails
        $actions->expects($this->once())->method('rollBack');
        $actions->expects($this->never())->method('commit');

        $admins = $this->createStub(CommunityAdminIdsQueryInterface::class);
        $admins->method('adminUserIds')->willReturn([]);

        $service = new AccountModerationService(
            $gateway,
            $actions,
            $this->createStub(ContentReportRepositoryInterface::class),
            $this->createStub(CommunityUserDirectoryQueryInterface::class),
            $admins,
            $this->createStub(Notifier::class),
            new MockClock(),
        );

        // The whole operation aborts and rolls back rather than leaving a banned user with no audit trail.
        $this->expectException(\RuntimeException::class);
        $service->ban('admin', 'target', 'spam');
    }

    public function testSelfAndAdminTargetsAreRefusedWithoutOpeningATransaction(): void
    {
        $gateway = $this->createStub(MemberModerationGatewayInterface::class);
        $admins = $this->createStub(CommunityAdminIdsQueryInterface::class);
        $admins->method('adminUserIds')->willReturn(['target-admin']);

        $actions = $this->createMock(ModerationActionRepositoryInterface::class);
        $actions->expects($this->never())->method('beginTransaction');

        $service = new AccountModerationService(
            $gateway,
            $actions,
            $this->createStub(ContentReportRepositoryInterface::class),
            $this->createStub(CommunityUserDirectoryQueryInterface::class),
            $admins,
            $this->createStub(Notifier::class),
            new MockClock(),
        );

        self::assertSame('forbidden', $service->ban('admin', 'admin', 'self'));
        self::assertSame('forbidden', $service->ban('admin', 'target-admin', 'other admin'));
    }
}
