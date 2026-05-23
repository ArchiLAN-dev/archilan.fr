<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Apworlds\Response;

final readonly class TemplateOption
{
    /**
     * @param string[]|null $validValues
     */
    public function __construct(
        public string $key,
        public string $description,
        public string $type,
        public mixed $defaultValue,
        public ?array $validValues,
        public ?int $rangeMin,
        public ?int $rangeMax,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $key = $data['key'] ?? '';
        $description = $data['description'] ?? '';
        $type = $data['type'] ?? 'text';

        if (!is_string($key) || !is_string($description) || !is_string($type)) {
            $key = is_string($key) ? $key : '';
            $description = is_string($description) ? $description : '';
            $type = is_string($type) ? $type : 'text';
        }

        $validValues = null;
        if (isset($data['validValues']) && is_array($data['validValues'])) {
            $validValues = array_values(array_filter($data['validValues'], 'is_string'));
        }

        $rangeMin = isset($data['rangeMin']) && is_int($data['rangeMin']) ? $data['rangeMin'] : null;
        $rangeMax = isset($data['rangeMax']) && is_int($data['rangeMax']) ? $data['rangeMax'] : null;

        return new self(
            key: $key,
            description: $description,
            type: $type,
            defaultValue: $data['defaultValue'] ?? null,
            validValues: $validValues,
            rangeMin: $rangeMin,
            rangeMax: $rangeMax,
        );
    }
}
