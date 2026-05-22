<?php

declare(strict_types=1);

namespace App\Identity\Application\Message;

use App\Identity\Domain\RefreshTokenRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CleanupRefreshTokensHandler
{
    public function __construct(
        private RefreshTokenRepositoryInterface $repository,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CleanupRefreshTokensMessage $message): void
    {
        $deleted = $this->repository->deleteStale(new \DateTimeImmutable());

        $this->logger->info('auth.cleanup_refresh_tokens', ['deleted' => $deleted]);
    }
}
