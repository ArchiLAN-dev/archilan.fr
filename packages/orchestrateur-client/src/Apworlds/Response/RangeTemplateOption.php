<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Apworlds\Response;

final readonly class RangeTemplateOption extends TemplateOption
{
    public function __construct(
        string $key,
        string $description,
        public ?int $default,
        public int $rangeMin,
        public int $rangeMax,
    ) {
        parent::__construct($key, $description);
    }

    /** @param array<string, mixed> $data */
    public static function fromData(string $key, string $description, array $data): self
    {
        $default = isset($data['defaultValue']) && is_int($data['defaultValue']) ? $data['defaultValue'] : null;
        $rangeMin = isset($data['rangeMin']) && is_int($data['rangeMin']) ? $data['rangeMin'] : 0;
        $rangeMax = isset($data['rangeMax']) && is_int($data['rangeMax']) ? $data['rangeMax'] : 0;

        return new self($key, $description, $default, $rangeMin, $rangeMax);
    }
}
