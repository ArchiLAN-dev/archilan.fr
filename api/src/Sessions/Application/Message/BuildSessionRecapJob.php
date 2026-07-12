<?php

declare(strict_types=1);

namespace App\Sessions\Application\Message;

/**
 * Dispatched after a run is archived (story 9.16 callback) to (re)build the
 * public session recap projection from the generation spoiler. Idempotent.
 */
final readonly class BuildSessionRecapJob
{
    public function __construct(public string $sessionId)
    {
    }
}
