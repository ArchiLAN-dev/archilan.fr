<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Apworlds\Response;

final readonly class ChoiceTemplateOption extends TemplateOption
{
    /**
     * @param string[]           $validValues
     * @param array<string, int> $weights
     */
    public function __construct(
        string $key,
        string $description,
        public string $default,
        public array $validValues,
        public array $weights = [],
    ) {
        parent::__construct($key, $description);
    }

    /** @param array<string, mixed> $data */
    public static function fromData(string $key, string $description, array $data): self
    {
        $default = is_string($data['defaultValue'] ?? null) ? $data['defaultValue'] : '';
        $validValues = [];
        if (isset($data['validValues']) && is_array($data['validValues'])) {
            $validValues = array_values(array_filter($data['validValues'], 'is_string'));
        }
        $weights = [];
        if (isset($data['weights']) && is_array($data['weights'])) {
            foreach ($data['weights'] as $k => $v) {
                if (is_string($k) && is_int($v)) {
                    $weights[$k] = $v;
                }
            }
        }

        return new self($key, $description, $default, $validValues, $weights);
    }
}
