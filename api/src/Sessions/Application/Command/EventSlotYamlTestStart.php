<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

/**
 * Story 11.11: how the test of an event slot's config started, the id to poll it with, and the files its game
 * reads when it could not start without one.
 */
final readonly class EventSlotYamlTestStart
{
    /**
     * @param list<array{setting: string, file: string, extension: string, description: ?string, md5s: list<string>, neededAtGeneration: bool}> $requiredFiles
     */
    public function __construct(
        public EventSlotYamlTestOutcome $outcome,
        public ?string $jobId = null,
        public array $requiredFiles = [],
    ) {
    }
}
