<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Query;

use App\Sessions\Application\Port\RunnerGatewayInterface;
use App\Sessions\Application\Support\GenerationFailureParser;

/**
 * Story 38.14: where a YAML test on an apworld candidate stands. The orchestrator keeps these results thirty
 * minutes, in memory: an unknown or expired test, or a runner that does not answer, reads as null.
 */
final readonly class ApworldCandidateYamlTestQuery
{
    public function __construct(private RunnerGatewayInterface $runnerGateway)
    {
    }

    /**
     * @return array{status: string, error: string|null}|null
     */
    public function result(string $jobId): ?array
    {
        $job = $this->runnerGateway->getSlotPreflight($jobId);
        if (null === $job) {
            return null;
        }
        $error = $job['error'];

        return [
            'status' => $job['status'],
            'error' => 'failed' === $job['status'] && '' !== $error ? GenerationFailureParser::summarize($error) : null,
        ];
    }
}
