<?php

declare(strict_types=1);

namespace App\PersonalRuns\Domain\Repository;

use App\PersonalRuns\Domain\Entity\RunArchive;

interface RunArchiveRepositoryInterface
{
    public function find(string $runId, string $userId): ?RunArchive;

    /**
     * The runs a member archived, whatever their part in them (story 16.21).
     *
     * @return list<string>
     */
    public function archivedRunIds(string $userId): array;

    public function save(RunArchive $archive): void;

    public function remove(RunArchive $archive): void;

    /** Every member's archive of the run: it was deleted, or it is being played again. */
    public function deleteByRunId(string $runId): void;
}
