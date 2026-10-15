<?php

declare(strict_types=1);

namespace App\Sessions\Application\Port;

interface PersonalRunAdvancerInterface
{
    /** @param list<string> $requiredFileIds held files the generation consumes (story 44.2) */
    public function autoAdvancePersonalRun(string $sessionId, array $requiredFileIds = []): void;

    public function markPersonalRunStopped(string $sessionId): void;
}
