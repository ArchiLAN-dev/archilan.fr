<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Adapter;

use App\PersonalRuns\Application\Port\RunBronzeInterface;
use App\Wallet\Application\Command\ReconcileRunBronze;
use App\Wallet\Application\Message\ReconcileRunBronzeJob;
use Symfony\Component\Messenger\MessageBusInterface;

/** Story 41.36: a private run's bronze, through the ledger. */
final readonly class LedgerRunBronze implements RunBronzeInterface
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private ReconcileRunBronze $reconcile,
    ) {
    }

    public function reconcileLater(string $runId): void
    {
        $this->messageBus->dispatch(new ReconcileRunBronzeJob($runId));
    }

    public function burnNow(string $runId): void
    {
        $this->reconcile->burnRun($runId);
    }
}
