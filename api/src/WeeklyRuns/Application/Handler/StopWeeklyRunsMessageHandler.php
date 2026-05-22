<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application\Handler;

use App\WeeklyRuns\Application\Message\StopWeeklyRunsMessage;
use App\WeeklyRuns\Application\WeeklyRunnerGatewayInterface;
use App\WeeklyRuns\Domain\WeeklyEntryRepositoryInterface;
use App\WeeklyRuns\Domain\WeeklyRunRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class StopWeeklyRunsMessageHandler
{
    public function __construct(
        private WeeklyRunRepositoryInterface $runs,
        private WeeklyEntryRepositoryInterface $entries,
        private WeeklyRunnerGatewayInterface $gateway,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(StopWeeklyRunsMessage $message): void
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));

        $activeRuns = $this->runs->findAllActive();

        foreach ($activeRuns as $run) {
            $activeEntries = $this->entries->findActiveEntriesForRun($run->getId());

            foreach ($activeEntries as $entry) {
                $sessionId = $entry->getExternalSessionId();
                if (null === $sessionId || '' === $sessionId) {
                    continue;
                }

                try {
                    $this->gateway->terminate($sessionId);
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to terminate weekly entry session', [
                        'externalSessionId' => $sessionId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $run->finish($now);
            $this->runs->flush();
        }
    }
}
