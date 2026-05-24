<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Sessions\Response;

use Archilan\OrchestratorClient\Exception\OrchestratorException;

final readonly class ConfigureResult
{
    /**
     * @param ConfigureSlotResult[] $slots
     */
    public function __construct(
        public bool $valid,
        public array $slots,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $valid = $data['valid'] ?? null;
        if (!is_bool($valid)) {
            throw new OrchestratorException("Missing or invalid field 'valid' in configure response");
        }

        $rawSlots = $data['slots'] ?? [];
        $slots = [];
        if (is_array($rawSlots)) {
            foreach ($rawSlots as $slot) {
                if (is_array($slot)) {
                    /** @var array<string, mixed> $slot */
                    $slots[] = ConfigureSlotResult::fromArray($slot);
                }
            }
        }

        return new self(valid: $valid, slots: $slots);
    }
}
