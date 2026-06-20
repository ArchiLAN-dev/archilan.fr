<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\AccountModerationService;
use App\Community\Application\CommunityAdminIdsQueryInterface;
use App\Community\Application\CommunityUserDirectoryQueryInterface;
use App\Community\Application\MemberModerationGatewayInterface;
use App\Community\Application\Notifier;
use App\Community\Domain\ContentReportRepositoryInterface;
use App\Community\Domain\ModerationActionRepositoryInterface;
use PHPUnit\Framework\TestCase;

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
        );

        self::assertSame('forbidden', $service->ban('admin', 'admin', 'self'));
        self::assertSame('forbidden', $service->ban('admin', 'target-admin', 'other admin'));
    }
}
