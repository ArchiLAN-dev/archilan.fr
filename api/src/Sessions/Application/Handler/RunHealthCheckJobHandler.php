<?php

declare(strict_types=1);

namespace App\Sessions\Application\Handler;

use App\Sessions\Application\Message\RunHealthCheckJob;
use App\Sessions\Infrastructure\PortPool;
use App\Sessions\Infrastructure\RunnerCallbackClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
final readonly class RunHealthCheckJobHandler
{
    private const MAX_FAILURES = 3;
    private const CHECK_INTERVAL_MS = 30000;

    public function __construct(
        private RunnerCallbackClient $callbackClient,
        private PortPool $portPool,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
        private string $runnerId,
    ) {
    }

    public function __invoke(RunHealthCheckJob $job): void
    {
        $sock = @fsockopen('localhost', $job->port, $errno, $errstr, 2.0);

        if (false !== $sock) {
            fclose($sock);

            $this->logger->info('runner.health_check.ok', [
                'session_id' => $job->sessionId,
                'port' => $job->port,
            ]);

            $this->messageBus->dispatch(
                new RunHealthCheckJob($job->sessionId, $job->port, $job->bridgePort, 0),
                [new DelayStamp(self::CHECK_INTERVAL_MS)],
            );

            return;
        }

        $failures = $job->consecutiveFailures + 1;

        $this->logger->warning('runner.health_check.failed', [
            'session_id' => $job->sessionId,
            'port' => $job->port,
            'consecutive_failures' => $failures,
            'error' => $errstr,
        ]);

        if ($failures >= self::MAX_FAILURES) {
            $this->portPool->release($job->port);
            $this->portPool->release($job->bridgePort);

            $this->callbackClient->sendCallback($job->sessionId, [
                'status' => 'crashed',
                'runner_id' => $this->runnerId,
            ]);

            $this->logger->error('runner.health_check.crashed', [
                'session_id' => $job->sessionId,
                'runner_id' => $this->runnerId,
            ]);

            return;
        }

        $this->messageBus->dispatch(
            new RunHealthCheckJob($job->sessionId, $job->port, $job->bridgePort, $failures),
            [new DelayStamp(self::CHECK_INTERVAL_MS)],
        );
    }
}
