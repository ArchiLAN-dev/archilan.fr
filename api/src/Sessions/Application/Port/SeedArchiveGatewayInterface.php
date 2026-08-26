<?php

declare(strict_types=1);

namespace App\Sessions\Application\Port;

/**
 * Reading and hosting a seed generated somewhere else (story 16.18).
 *
 * Kept apart from {@see RunnerGatewayInterface}, which is about the sessions we generate ourselves:
 * an imported archive never goes through generation, and the only two things we need from the
 * orchestrator are "what is in this file" and "host it".
 */
interface SeedArchiveGatewayInterface
{
    /**
     * Read the slot table of an output archive.
     *
     * The archive comes from a member and a multidata is a pickle, so parsing happens inside the
     * orchestrator's one-shot, network-disabled Archipelago container, through Archipelago's own
     * allowlisting unpickler - never in this process.
     *
     * Returns the archive's slots, or an `error` explaining why it is unusable. The error is meant
     * to be shown to the member: "this is not a seed" is a thing they can act on.
     *
     * @return array{slots: list<array{slot: int, name: string, game: string, type: int}>, seedName: string}|array{error: string}
     */
    public function inspect(string $archive, string $filename): array;

    /**
     * Launch a session on a pre-generated archive read from object storage. No generation runs.
     *
     * @param list<array{name: string, game: string}> $slotNames     the multiworld roster, ordered by
     *                                                               Archipelago slot number, so the bridge
     *                                                               knows which slot to attach to - an
     *                                                               imported seed has no observer slot
     * @param array<string, scalar>                   $serverOptions
     */
    public function launchFromArchive(
        string $sessionId,
        string $outputKey,
        string $adminPassword,
        ?string $serverPassword,
        array $slotNames,
        array $serverOptions = [],
    ): void;
}
