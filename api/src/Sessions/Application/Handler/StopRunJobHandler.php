<?php

declare(strict_types=1);

namespace App\Sessions\Application\Handler;

use App\Sessions\Application\Message\StopRunJob;
use App\Sessions\Infrastructure\PortPool;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Process\Process;

#[AsMessageHandler]
final readonly class StopRunJobHandler
{
    public function __construct(
        private PortPool $portPool,
        private LoggerInterface $logger,
        private string $runnerId,
    ) {
    }

    public function __invoke(StopRunJob $job): void
    {
        $this->logger->info('runner.stop_job.received', [
            'session_id' => $job->sessionId,
            'runner_id' => $this->runnerId,
        ]);

        $containerName = 'archipelago-run-'.$job->sessionId;

        $stopProcess = new Process(['docker', 'stop', '--time', '10', $containerName]);
        $stopProcess->setTimeout(30);
        $stopProcess->run();

        if (!$stopProcess->isSuccessful()) {
            $this->logger->warning('runner.stop_job.stop_failed', [
                'session_id' => $job->sessionId,
                'container' => $containerName,
                'exit_code' => $stopProcess->getExitCode(),
                'stderr' => $stopProcess->getErrorOutput(),
            ]);
        }

        $rmProcess = new Process(['docker', 'rm', '--force', $containerName]);
        $rmProcess->setTimeout(15);
        $rmProcess->run();

        if (!$rmProcess->isSuccessful()) {
            $this->logger->warning('runner.stop_job.rm_failed', [
                'session_id' => $job->sessionId,
                'container' => $containerName,
                'exit_code' => $rmProcess->getExitCode(),
                'stderr' => $rmProcess->getErrorOutput(),
            ]);
        }

        if ($job->port > 0) {
            $this->portPool->release($job->port);
        }

        if ($job->bridgePort > 0) {
            $this->portPool->release($job->bridgePort);
        }

        $this->logger->info('runner.stop_job.done', [
            'session_id' => $job->sessionId,
            'container' => $containerName,
            'port_released' => $job->port,
            'bridge_port_released' => $job->bridgePort,
        ]);
    }
}
