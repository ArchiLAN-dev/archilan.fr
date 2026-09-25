<?php

declare(strict_types=1);

namespace App\CatalogSync\Application\Handler;

use App\CatalogSync\Application\Command\CheckApworldUpdatesService;
use App\CatalogSync\Application\Message\CheckApworldUpdatesMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The nightly apworld version check (story 38.5). Without a GitHub token the checker does nothing
 * and says so in its own log line; the report then simply counts zero games.
 */
#[AsMessageHandler]
final readonly class CheckApworldUpdatesHandler
{
    public function __construct(
        private CheckApworldUpdatesService $service,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CheckApworldUpdatesMessage $message): void
    {
        $report = $this->service->checkAll();

        $this->logger->info('catalog_sync.nightly_check_done', [
            'checked' => $report->checked,
            'rateLimitHit' => $report->rateLimitHit,
            'updatesAvailable' => array_map(
                static fn ($update): string => sprintf('%s -> %s', $update->gameName, $update->latestTag),
                $report->updatesAvailable,
            ),
        ]);
    }
}
