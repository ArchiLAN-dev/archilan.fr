<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Containers\Response;

use Archilan\OrchestratorClient\Exception\OrchestratorException;

final readonly class CreateContainerResult
{
    public function __construct(
        public string $sessionId,
        public int $port,
        public string $status,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $sessionId = $data['sessionId'] ?? null;
        if (!is_string($sessionId)) {
            throw new OrchestratorException("Missing or invalid field 'sessionId' in create container response");
        }

        $portRaw = $data['port'] ?? null;
        $port = is_int($portRaw) ? $portRaw : (is_numeric($portRaw) ? (int) $portRaw : null);
        if (null === $port) {
            throw new OrchestratorException("Missing or invalid field 'port' in create container response");
        }

        $status = $data['status'] ?? null;
        if (!is_string($status)) {
            throw new OrchestratorException("Missing or invalid field 'status' in create container response");
        }

        return new self(sessionId: $sessionId, port: $port, status: $status);
    }
}
