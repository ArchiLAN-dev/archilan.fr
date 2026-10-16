<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Handler;

use App\GameSelection\Application\Command\RecordGenerationRequiredFile;
use App\GameSelection\Application\Message\RecordGenerationRequiredFileJob;
use App\Sessions\Application\Support\RequiredGenerationFile;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RecordGenerationRequiredFileJobHandler
{
    public function __construct(private RecordGenerationRequiredFile $record)
    {
    }

    public function __invoke(RecordGenerationRequiredFileJob $job): void
    {
        $file = RequiredGenerationFile::fromRecord($job->requiredFile);
        if (null !== $file) {
            $this->record->record($job->candidateGameIds, $file);
        }
    }
}
