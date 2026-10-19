<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\PersonalRuns\Application\Port\RunBronzeInterface;

/** Records which runs had their bronze reconciled or burned (story 41.39 tests). */
final class SpyRunBronze implements RunBronzeInterface
{
    /** @var list<string> */
    public array $reconciled = [];

    /** @var list<string> */
    public array $burned = [];

    public ?\Throwable $burnFailure = null;

    public function reconcileLater(string $runId): void
    {
        $this->reconciled[] = $runId;
    }

    public function burnNow(string $runId): void
    {
        if (null !== $this->burnFailure) {
            throw $this->burnFailure;
        }
        $this->burned[] = $runId;
    }
}
