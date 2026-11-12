<?php

declare(strict_types=1);

namespace App\Streaming\Domain\Repository;

use App\Streaming\Domain\Entity\LocalMediaServer;
use App\Streaming\Domain\Entity\LocalStream;

/** Story 47.6: the state the régie agent reports for an event. */
interface LocalStreamRepositoryInterface
{
    /** @return list<LocalStream> */
    public function streamsOf(string $eventId): array;

    /** v0.66.1: one registrant's stream, without loading the whole event's. */
    public function streamOf(string $eventId, string $registrationId): ?LocalStream;

    public function serverOf(string $eventId): ?LocalMediaServer;

    /**
     * One report, written at once.
     *
     * @param list<LocalStream> $streams
     */
    public function saveReport(LocalMediaServer $server, array $streams): void;
}
