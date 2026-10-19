<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\Repository\PelleMovementRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Story 41.36: a private run's bronze lives as long as the run.
 *
 * Reconciles the ledger with the run's status rather than reacting to one transition, so the order its jobs run in
 * never matters: an ended run (completed or cancelled) has its remaining bronze burned; a run that is playable again
 * (a cancelled run restored) gets back every burn not given back yet. Cancelled then restored at once, both jobs see
 * a live run with nothing burned and do nothing.
 *
 * The keys carry the burn's rank (`bronze-expired:{run}:{member}:{n}`): a run cancelled, restored and cancelled
 * again burns twice, which a key without the rank would refuse.
 */
final readonly class ReconcileRunBronze
{
    private const array ENDED = [Run::STATUS_COMPLETED, Run::STATUS_CANCELLED];

    public function __construct(
        private RunRepositoryInterface $runs,
        private PelleMovementRepositoryInterface $movements,
        private RecordPelleMovement $record,
        private LoggerInterface $logger,
    ) {
    }

    public function reconcile(string $runId): void
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return;
        }

        if (\in_array($run->getStatus(), self::ENDED, true)) {
            $this->burn($run);

            return;
        }
        $this->restore($run);
    }

    /**
     * Burns what is left, whatever the run's status: called right before a run is deleted, while it still exists
     * for the ledger to name.
     */
    public function burnRun(string $runId): void
    {
        $run = $this->runs->findById($runId);
        if ($run instanceof Run) {
            $this->burn($run);
        }
    }

    private function burn(Run $run): void
    {
        $burns = $this->movements->bronzeBurnsOfRun($run->getId());
        $label = sprintf('Fin de « %s »', $run->getTitle());

        foreach ($this->movements->bronzeBalancesOfRun($run->getId()) as $userId => $balance) {
            $rank = ($burns[$userId]['burns'] ?? 0) + 1;
            // The system's doing, so it reaches a banned member too (as event pelles do, story 41.2).
            $this->write($run, $userId, -$balance, PelleReason::BronzeExpired, $label, sprintf('bronze-expired:%s:%s:%d', $run->getId(), $userId, $rank));
        }
    }

    /** Gives back in one line everything burned and not given back yet. */
    private function restore(Run $run): void
    {
        $label = sprintf('« %s » reprend', $run->getTitle());

        foreach ($this->movements->bronzeBurnsOfRun($run->getId()) as $userId => $ledger) {
            $owed = $ledger['burned'] - $ledger['restored'];
            if ($owed > 0) {
                $this->write($run, $userId, $owed, PelleReason::BronzeRestored, $label, sprintf('bronze-restored:%s:%s:%d', $run->getId(), $userId, $ledger['restores'] + 1));
            }
        }
    }

    private function write(Run $run, string $userId, int $amount, PelleReason $reason, string $label, string $key): void
    {
        try {
            $this->record->record(new RecordPelleMovementInput(
                $userId, $amount, PelleKind::Bronze, null, $reason, $label, null, $key,
                byAdmin: true,
                runId: $run->getId(),
            ));
        } catch (NotFoundException $e) {
            // A deleted account: its movements stay with the anonymised member, nothing to move.
            $this->logger->info('wallet.bronze.reconcile_skipped', ['runId' => $run->getId(), 'userId' => $userId, 'reason' => $e->getMessage()]);
        }
    }
}
