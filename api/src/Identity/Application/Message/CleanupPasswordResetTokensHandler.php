<?php

declare(strict_types=1);

namespace App\Identity\Application\Message;

use App\Identity\Domain\PasswordResetTokenRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CleanupPasswordResetTokensHandler
{
    public function __construct(
        private PasswordResetTokenRepositoryInterface $repository,
        private LoggerInterface $logger,
        private int $tokenConsumedGraceDays,
    ) {
    }

    public function __invoke(CleanupPasswordResetTokensMessage $message): void
    {
        $now = new \DateTimeImmutable();
        $deleted = $this->repository->deleteStale($now, $now->modify(sprintf('-%d days', $this->tokenConsumedGraceDays)));

        $this->logger->info('auth.cleanup_password_reset_tokens', ['deleted' => $deleted]);
    }
}
