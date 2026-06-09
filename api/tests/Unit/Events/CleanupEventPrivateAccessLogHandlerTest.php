<?php

declare(strict_types=1);

namespace App\Tests\Unit\Events;

use App\Events\Application\Message\CleanupEventPrivateAccessLogHandler;
use App\Events\Application\Message\CleanupEventPrivateAccessLogMessage;
use App\Events\Domain\EventPrivateAccessLogRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CleanupEventPrivateAccessLogHandlerTest extends TestCase
{
    public function testAppliesRetentionAndLogs(): void
    {
        $repo = $this->createMock(EventPrivateAccessLogRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('deleteOlderThan')
            ->with(self::callback(function (\DateTimeImmutable $threshold): bool {
                $days = (new \DateTimeImmutable())->diff($threshold)->days;

                return $threshold < new \DateTimeImmutable() && 364 <= $days && $days <= 366;
            }))
            ->willReturn(2);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('data.cleanup_event_private_access_log', ['deleted' => 2]);

        (new CleanupEventPrivateAccessLogHandler($repo, $logger, 365))(new CleanupEventPrivateAccessLogMessage());
    }
}
