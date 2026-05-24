<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Containers\Response;

use Archilan\OrchestratorClient\Exception\OrchestratorException;

final readonly class ContainerResponse
{
    public function __construct(
        public string $sessionId,
        public int $port,
        public string $status,
        public ?string $containerId,
        public string $image,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $sessionId = $data['sessionId'] ?? null;
        if (!is_string($sessionId)) {
            throw new OrchestratorException("Missing or invalid field 'sessionId' in container response");
        }

        $portRaw = $data['port'] ?? null;
        $port = is_int($portRaw) ? $portRaw : (is_numeric($portRaw) ? (int) $portRaw : null);
        if (null === $port) {
            throw new OrchestratorException("Missing or invalid field 'port' in container response");
        }

        $status = $data['status'] ?? null;
        if (!is_string($status)) {
            throw new OrchestratorException("Missing or invalid field 'status' in container response");
        }

        $containerIdRaw = $data['containerId'] ?? null;
        $containerId = is_string($containerIdRaw) ? $containerIdRaw : null;

        $image = $data['image'] ?? null;
        if (!is_string($image)) {
            throw new OrchestratorException("Missing or invalid field 'image' in container response");
        }

        $createdAt = $data['createdAt'] ?? null;
        if (!is_string($createdAt)) {
            throw new OrchestratorException("Missing or invalid field 'createdAt' in container response");
        }

        $updatedAt = $data['updatedAt'] ?? null;
        if (!is_string($updatedAt)) {
            throw new OrchestratorException("Missing or invalid field 'updatedAt' in container response");
        }

        return new self(
            sessionId: $sessionId,
            port: $port,
            status: $status,
            containerId: $containerId,
            image: $image,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }
}
