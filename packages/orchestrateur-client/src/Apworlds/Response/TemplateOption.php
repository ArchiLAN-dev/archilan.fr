<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Apworlds\Response;

abstract readonly class TemplateOption
{
    public function __construct(
        public string $key,
        public string $description,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $key = is_string($data['key'] ?? null) ? $data['key'] : '';
        $description = is_string($data['description'] ?? null) ? $data['description'] : '';
        $type = is_string($data['type'] ?? null) ? $data['type'] : '';

        return match ($type) {
            'range'   => RangeTemplateOption::fromData($key, $description, $data),
            'choice'  => ChoiceTemplateOption::fromData($key, $description, $data),
            'toggle'  => ToggleTemplateOption::fromData($key, $description, $data),
            'weights' => WeightsTemplateOption::fromData($key, $description, $data),
            default   => TextTemplateOption::fromData($key, $description, $data),
        };
    }
}
