<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Support;

/**
 * Where the orchestrator's test generation of an apworld stands (story 38.33), so the admin sees a test that
 * runs and not a status that waits. A pending verdict waits for a container slot (queued) until its run starts
 * generating (running); a settled one is finished, the decision pass records it shortly. Idle: the orchestrator
 * holds no test for that apworld, or does not answer - nothing is going to come, the test can be started again.
 */
enum ApworldTestState: string
{
    case Running = 'running';
    case Queued = 'queued';
    case Finished = 'finished';
    case Idle = 'idle';

    /**
     * @param array{status: string, startedAt?: string|null}|null $verdict the orchestrator's verdict for the apworld
     */
    public static function of(?array $verdict): self
    {
        return match ($verdict['status'] ?? '') {
            'pending' => null !== ($verdict['startedAt'] ?? null) ? self::Running : self::Queued,
            'passed', 'failed', 'skipped' => self::Finished,
            default => self::Idle,
        };
    }

    /** A test is coming: starting another one would only race it. */
    public function isUnderway(): bool
    {
        return self::Idle !== $this;
    }
}
