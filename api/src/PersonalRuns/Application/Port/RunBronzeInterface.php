<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Port;

/**
 * Story 41.36: a private run's bronze pelles follow the run. Told after the run's own change is committed.
 */
interface RunBronzeInterface
{
    /** The run ended or came back: its bronze is burned or given back, from the worker. */
    public function reconcileLater(string $runId): void;

    /** The run is about to be deleted: what is left burns now, while the run still exists. */
    public function burnNow(string $runId): void;
}
