<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Message;

final readonly class StopPersonalRunJob
{
    public function __construct(public string $personalRunId)
    {
    }
}
