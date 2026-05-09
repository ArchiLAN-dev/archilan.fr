<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure;

final class NullDockerPortScanner implements DockerPortScannerInterface
{
    /** @return list<int> */
    public function scanAllocatedPorts(): array
    {
        return [];
    }
}
