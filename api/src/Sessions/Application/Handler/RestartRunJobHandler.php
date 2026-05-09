<?php

declare(strict_types=1);

namespace App\Sessions\Application\Handler;

use App\Sessions\Application\Message\RestartRunJob;
use App\Sessions\Infrastructure\PortPool;
use App\Sessions\Infrastructure\RunnerCallbackClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Process\Process;

#[AsMessageHandler]
final readonly class RestartRunJobHandler
{
    public function __construct(
        private RunnerCallbackClient $callbackClient,
        private PortPool $portPool,
        private LoggerInterface $logger,
        private string $runnerId,
        private string $runnerHost,
    ) {
    }

    public function __invoke(RestartRunJob $job): void
    {
        $this->logger->info('runner.restart_job.received', [
            'session_id' => $job->sessionId,
            'runner_id' => $this->runnerId,
        ]);

        $containerName = 'archipelago-run-'.$job->sessionId;

        $process = new Process(['docker', 'restart', $containerName]);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->portPool->release($job->port);
            $this->portPool->release($job->bridgePort);

            $this->callbackClient->sendCallback($job->sessionId, [
                'status' => 'failed',
                'errors' => ['Impossible de redémarrer le container Archipelago.'],
            ]);

            $this->logger->error('runner.restart_job.docker_failed', [
                'session_id' => $job->sessionId,
                'exit_code' => $process->getExitCode(),
            ]);

            return;
        }

        // CRASHED→LAUNCHING→RUNNING: the state machine forbids CRASHED→RUNNING directly.
        $this->callbackClient->sendCallback($job->sessionId, ['status' => 'launching']);
        $this->callbackClient->sendCallback($job->sessionId, [
            'status' => 'running',
            'host' => $this->runnerHost,
            'port' => $job->port,
            'bridge_port' => $job->bridgePort,
            'password' => $job->password,
            'server_password' => $job->serverPassword,
        ]);

        $this->logger->info('runner.restart_job.succeeded', [
            'session_id' => $job->sessionId,
            'port' => $job->port,
            'bridge_port' => $job->bridgePort,
        ]);
    }
}
