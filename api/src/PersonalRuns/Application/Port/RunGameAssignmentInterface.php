<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Port;

/**
 * The slice of game selection the run-creation path needs (story 17.23).
 *
 * Narrow on purpose: creating a run with a preselected game only has to know whether that game may
 * be added, and how to attach it to the creator's slot. Depending on the whole
 * {@see \App\PersonalRuns\Application\Service\PersonalRunGameSelection} - a final class with six
 * collaborators - would make PersonalRunDrafts impossible to build in a unit test, which is exactly
 * how this interface came about.
 *
 * The rules themselves stay in the implementation, so the creation path and the normal selection
 * path can never disagree about what is addable.
 */
interface RunGameAssignmentInterface
{
    /**
     * Why this game cannot be added to a brand-new selection - empty when it can.
     *
     * @return list<string>
     */
    public function reasonsGameCannotBeAdded(string $gameId): array;

    /**
     * Attaches the game to the creator's slot on a freshly created run.
     */
    public function assignGameToCreator(string $runId, string $ownerId, string $gameId): void;
}
