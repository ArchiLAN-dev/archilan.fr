<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure;

interface DockerPortScannerInterface
{
    /** @return list<int> */
    public function scanAllocatedPorts(): array;
}
