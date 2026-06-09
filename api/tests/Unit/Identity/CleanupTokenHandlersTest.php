<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Application\Message\CleanupEmailConfirmationTokensHandler;
use App\Identity\Application\Message\CleanupEmailConfirmationTokensMessage;
use App\Identity\Application\Message\CleanupPasswordResetTokensHandler;
use App\Identity\Application\Message\CleanupPasswordResetTokensMessage;
use App\Identity\Domain\EmailConfirmationTokenRepositoryInterface;
use App\Identity\Domain\PasswordResetTokenRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CleanupTokenHandlersTest extends TestCase
{
    public function testEmailConfirmationHandlerAppliesGraceAndLogs(): void
    {
        $repo = $this->createMock(EmailConfirmationTokenRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('deleteStale')
            ->with(
                self::isInstanceOf(\DateTimeImmutable::class),
                self::callback(function (\DateTimeImmutable $consumedBefore): bool {
                    // ~7 days in the past
                    $days = (new \DateTimeImmutable())->diff($consumedBefore)->days;

                    return $consumedBefore < new \DateTimeImmutable() && 6 <= $days && $days <= 8;
                }),
            )
            ->willReturn(3);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('auth.cleanup_email_confirmation_tokens', ['deleted' => 3]);

        (new CleanupEmailConfirmationTokensHandler($repo, $logger, 7))(new CleanupEmailConfirmationTokensMessage());
    }

    public function testPasswordResetHandlerAppliesGraceAndLogs(): void
    {
        $repo = $this->createMock(PasswordResetTokenRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('deleteStale')
            ->willReturn(5);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('auth.cleanup_password_reset_tokens', ['deleted' => 5]);

        (new CleanupPasswordResetTokensHandler($repo, $logger, 7))(new CleanupPasswordResetTokensMessage());
    }
}
