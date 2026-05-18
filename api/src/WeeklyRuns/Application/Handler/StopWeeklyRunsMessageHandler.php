<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application\Handler;

use App\WeeklyRuns\Application\Message\StopWeeklyRunsMessage;
use App\WeeklyRuns\Application\WeeklyRunnerGatewayInterface;
use App\WeeklyRuns\Domain\WeeklyRun;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class StopWeeklyRunsMessageHandler
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private WeeklyRunnerGatewayInterface $gateway,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(StopWeeklyRunsMessage $message): void
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));

        $runIds = $this->connection->createQueryBuilder()
            ->select('wr.id')
            ->from('weekly_runs', 'wr')
            ->where('wr.status = :status')
            ->setParameter('status', WeeklyRun::STATUS_ACTIVE)
            ->executeQuery()
            ->fetchFirstColumn();

        foreach ($runIds as $runId) {
            if (!is_string($runId)) {
                continue;
            }

            $run = $this->entityManager->find(WeeklyRun::class, $runId);
            if (!$run instanceof WeeklyRun) {
                continue;
            }

            $entryRows = $this->connection->createQueryBuilder()
                ->select('we.id', 'we.external_session_id')
                ->from('weekly_entries', 'we')
                ->where('we.weekly_run_id = :runId')
                ->andWhere('we.external_session_id IS NOT NULL')
                ->andWhere('we.goal_reached_at IS NULL')
                ->setParameter('runId', $runId)
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($entryRows as $row) {
                $sessionId = $row['external_session_id'] ?? null;
                if (!is_string($sessionId) || '' === $sessionId) {
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
            $this->entityManager->flush();
        }
    }
}
