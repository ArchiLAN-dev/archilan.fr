<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure;

use Symfony\Component\Process\Process;

final class DockerPortScanner implements DockerPortScannerInterface
{
    /** @return list<int> */
    public function scanAllocatedPorts(): array
    {
        $process = new Process([
            'docker', 'ps',
            '--filter', 'name=archipelago-run-',
            '--format', '{{.Ports}}',
        ]);
        $process->run();

        if (!$process->isSuccessful()) {
            return [];
        }

        preg_match_all('/(?:0\.0\.0\.0|::):(\d+)->/', $process->getOutput(), $matches);

        return array_map('intval', $matches[1]);
    }
}
