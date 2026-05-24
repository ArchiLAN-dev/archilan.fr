<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Sessions\Response;

use Archilan\OrchestratorClient\Exception\OrchestratorException;

final readonly class PreflightSlotResult
{
    /**
     * @param string[] $errors
     */
    public function __construct(
        public string $slotId,
        public string $proposedName,
        public array $errors,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $slotId = $data['slotId'] ?? null;
        if (!is_string($slotId)) {
            throw new OrchestratorException("Missing or invalid field 'slotId' in preflight slot result");
        }

        $proposedName = $data['proposedName'] ?? null;
        if (!is_string($proposedName)) {
            throw new OrchestratorException("Missing or invalid field 'proposedName' in preflight slot result");
        }

        $rawErrors = $data['errors'] ?? [];
        $errors = [];
        if (is_array($rawErrors)) {
            foreach ($rawErrors as $e) {
                if (is_string($e)) {
                    $errors[] = $e;
                }
            }
        }

        return new self(slotId: $slotId, proposedName: $proposedName, errors: $errors);
    }
}
