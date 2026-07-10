<?php

declare(strict_types=1);

namespace App\Identity\Application\Handler;

use App\Identity\Application\Message\CleanupPasswordResetTokensMessage;
use App\Identity\Domain\Repository\PasswordResetTokenRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CleanupPasswordResetTokensHandler
{
    public function __construct(
        private PasswordResetTokenRepositoryInterface $repository,
        private LoggerInterface $logger,
        private ClockInterface $clock,
        private int $tokenConsumedGraceDays,
    ) {
    }

    public function __invoke(CleanupPasswordResetTokensMessage $message): void
    {
        $now = $this->clock->now();
        $deleted = $this->repository->deleteStale($now, $now->modify(sprintf('-%d days', $this->tokenConsumedGraceDays)));

        $this->logger->info('auth.cleanup_password_reset_tokens', ['deleted' => $deleted]);
    }
}
