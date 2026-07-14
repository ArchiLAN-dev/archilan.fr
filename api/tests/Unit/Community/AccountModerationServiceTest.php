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
        );

        self::assertSame('forbidden', $service->ban('admin', 'admin', 'self'));
        self::assertSame('forbidden', $service->ban('admin', 'target-admin', 'other admin'));
    }
}
