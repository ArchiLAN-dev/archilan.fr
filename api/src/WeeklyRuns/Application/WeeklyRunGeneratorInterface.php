<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

interface WeeklyRunGeneratorInterface
{
    /**
     * Runs ArchipelagoGenerate for the given weekly run and returns the absolute path
     * to the produced .archipelago seed file inside the workspace.
     *
     * @throws \RuntimeException when generation fails
     */
    public function generate(
        string $weeklyRunId,
        string $apworldStorageKey,
        string $templateYaml,
        string $seed,
    ): string;
}
