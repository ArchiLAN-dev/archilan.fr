<?php

declare(strict_types=1);

namespace App\Wallet\Application\Handler;

use App\Wallet\Application\Command\ReconcileRunBronze;
use App\Wallet\Application\Message\ReconcileRunBronzeJob;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Story 41.36: burns an ended run's bronze, or gives back what a restored run lost. Safe to run twice. */
#[AsMessageHandler]
final readonly class ReconcileRunBronzeJobHandler
{
    public function __construct(private ReconcileRunBronze $reconcile)
    {
    }

    public function __invoke(ReconcileRunBronzeJob $job): void
    {
        $this->reconcile->reconcile($job->runId);
    }
}
