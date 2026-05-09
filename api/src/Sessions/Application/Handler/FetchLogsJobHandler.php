<?php

declare(strict_types=1);

namespace App\Sessions\Application\Handler;

use App\Sessions\Application\Message\FetchLogsJob;
use App\Sessions\Infrastructure\RunnerCallbackClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Process\Process;

#[AsMessageHandler]
final readonly class FetchLogsJobHandler
{
    public function __construct(
        private RunnerCallbackClient $callbackClient,
        private LoggerInterface $logger,
        private string $runnerId,
    ) {
    }

    public function __invoke(FetchLogsJob $job): void
    {
        $containerName = 'archipelago-run-'.$job->sessionId;

        $process = new Process(['docker', 'logs', '--tail', '200', '--timestamps', $containerName]);
        $process->setTimeout(30);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();

        $this->callbackClient->sendCallback($job->sessionId, [
            'status' => 'logs',
            'output' => $output,
        ]);

        $this->logger->info('runner.fetch_logs_job.done', [
            'session_id' => $job->sessionId,
            'runner_id' => $this->runnerId,
            'output_length' => strlen($output),
        ]);
    }
}
