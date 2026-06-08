<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

interface WeeklyRunGeneratorInterface
{
    /**
     * Deterministic prefix for the orchestrator session that generates a weekly run's
     * world. The full id is "weekly-gen-{weeklyRunId}"; the webhook parses it back.
     */
    public const GENERATOR_SESSION_PREFIX = 'weekly-gen-';

    /**
     * Dispatches (non-blocking) the multiworld generation for the given weekly run
     * against the deterministic generator session "weekly-gen-{weeklyRunId}". The
     * orchestrator generates asynchronously and signals completion via the
     * `session.generated` webhook, which marks the run launchable. This method does
     * NOT poll or wait for generation to finish.
     *
     * @throws \RuntimeException when the dispatch itself fails (e.g. orchestrator unreachable)
     */
    public function generate(
        string $weeklyRunId,
        string $apworldStorageKey,
        string $templateYaml,
        string $seed,
    ): void;
}
