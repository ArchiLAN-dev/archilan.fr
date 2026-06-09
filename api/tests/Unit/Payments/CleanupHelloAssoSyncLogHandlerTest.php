<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payments;

use App\Payments\Application\Message\CleanupHelloAssoSyncLogHandler;
use App\Payments\Application\Message\CleanupHelloAssoSyncLogMessage;
use App\Payments\Domain\HelloAssoSyncLogRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CleanupHelloAssoSyncLogHandlerTest extends TestCase
{
    public function testAppliesRetentionAndLogs(): void
    {
        $repo = $this->createMock(HelloAssoSyncLogRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('deleteOlderThan')
            ->with(self::callback(function (\DateTimeImmutable $threshold): bool {
                $days = (new \DateTimeImmutable())->diff($threshold)->days;

                return $threshold < new \DateTimeImmutable() && 89 <= $days && $days <= 91;
            }))
            ->willReturn(4);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('data.cleanup_helloasso_sync_log', ['deleted' => 4]);

        (new CleanupHelloAssoSyncLogHandler($repo, $logger, 90))(new CleanupHelloAssoSyncLogMessage());
    }
}
