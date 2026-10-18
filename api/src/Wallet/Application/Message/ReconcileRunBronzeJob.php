<?php

declare(strict_types=1);

namespace App\Wallet\Application\Message;

/** Story 41.36: a private run ended or came back, its bronze follows (burned or given back), from the worker. */
final readonly class ReconcileRunBronzeJob
{
    public function __construct(public string $runId)
    {
    }
}
