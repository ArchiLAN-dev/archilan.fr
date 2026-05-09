<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

final readonly class PortPoolReconstructionListener
{
    public function __construct(
        private PortPool $portPool,
        private DockerPortScannerInterface $scanner,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerStartedEvent $event): void
    {
        $ports = $this->scanner->scanAllocatedPorts();
        $this->portPool->markAllocated($ports);

        $this->logger->info('runner.port_pool.reconstructed', [
            'allocated' => $ports,
            'available' => $this->portPool->availableCount(),
        ]);
    }
}
