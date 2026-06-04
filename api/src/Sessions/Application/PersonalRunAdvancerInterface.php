<?php

declare(strict_types=1);

namespace App\Sessions\Application;

interface PersonalRunAdvancerInterface
{
    public function autoAdvancePersonalRun(string $sessionId): void;

    public function markPersonalRunStopped(string $sessionId): void;
}
