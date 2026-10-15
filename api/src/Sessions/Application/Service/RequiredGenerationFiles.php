<?php

declare(strict_types=1);

namespace App\Sessions\Application\Service;

use App\Sessions\Application\Exception\RequiredGenerationFileRefusedException;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use App\Sessions\Application\Support\ProvidedGenerationFile;

/**
 * The files a world reads at generation (story 44.2). ArchiLAN never stores one: each is handed to the
 * orchestrator during the launcher's request, which holds it in memory and gives back a one-use id. Only
 * that id travels on - in the launch job, then in the generation call that consumes it.
 */
final readonly class RequiredGenerationFiles
{
    public function __construct(private RunnerGatewayInterface $runnerGateway)
    {
    }

    /**
     * All or nothing: when one file is refused, those already held are dropped.
     *
     * @param list<ProvidedGenerationFile> $files
     *
     * @return list<string> the held ids, in the order of $files
     *
     * @throws RequiredGenerationFileRefusedException
     */
    public function stage(array $files): array
    {
        $ids = [];
        try {
            foreach ($files as $file) {
                $ids[] = $this->runnerGateway->stageRequiredFile($file->setting, $file->path, $file->filename);
            }
        } catch (RequiredGenerationFileRefusedException $e) {
            $this->release($ids);

            throw $e;
        }

        return $ids;
    }

    /** @param list<string> $ids held files the launch no longer needs; best effort, they expire anyway */
    public function release(array $ids): void
    {
        foreach ($ids as $id) {
            $this->runnerGateway->releaseRequiredFile($id);
        }
    }
}
