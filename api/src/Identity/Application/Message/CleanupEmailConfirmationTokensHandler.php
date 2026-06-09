<?php

declare(strict_types=1);

namespace App\Identity\Application\Message;

use App\Identity\Domain\EmailConfirmationTokenRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CleanupEmailConfirmationTokensHandler
{
    public function __construct(
        private EmailConfirmationTokenRepositoryInterface $repository,
        private LoggerInterface $logger,
        private int $tokenConsumedGraceDays,
    ) {
    }

    public function __invoke(CleanupEmailConfirmationTokensMessage $message): void
    {
        $now = new \DateTimeImmutable();
        $deleted = $this->repository->deleteStale($now, $now->modify(sprintf('-%d days', $this->tokenConsumedGraceDays)));

        $this->logger->info('auth.cleanup_email_confirmation_tokens', ['deleted' => $deleted]);
    }
}
