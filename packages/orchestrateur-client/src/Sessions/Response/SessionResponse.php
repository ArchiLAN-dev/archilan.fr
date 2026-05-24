<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Sessions\Response;

use Archilan\OrchestratorClient\Exception\OrchestratorException;

final readonly class SessionResponse
{
    public function __construct(
        public string $sessionId,
        public string $status,
        public ?int $bridgePort,
        public ?int $apPort,
        public ?string $serverPassword,
        public ?string $outputFile,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            sessionId: self::requireString($data, 'sessionId'),
            status: self::requireString($data, 'status'),
            bridgePort: self::optionalInt($data, 'bridgePort'),
            apPort: self::optionalInt($data, 'apPort'),
            serverPassword: self::optionalString($data, 'serverPassword'),
            outputFile: self::optionalString($data, 'outputFile'),
            createdAt: self::requireString($data, 'createdAt'),
            updatedAt: self::requireString($data, 'updatedAt'),
        );
    }

    /** @param array<string, mixed> $data */
    private static function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new OrchestratorException(sprintf("Missing or invalid field '%s' in orchestrateur response", $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @param array<string, mixed> $data */
    private static function optionalInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) || is_string($value)) {
            return (int) $value;
        }

        return null;
    }
}
