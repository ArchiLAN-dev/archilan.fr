<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Sessions\Response;

final readonly class ConfigureSlotResult
{
    /**
     * @param string[] $errors
     */
    public function __construct(
        public string $playerName,
        public array $errors,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $playerName = $data['playerName'] ?? '';
        if (!is_string($playerName)) {
            $playerName = '';
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

        return new self(playerName: $playerName, errors: $errors);
    }
}
