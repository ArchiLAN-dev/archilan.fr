<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Apworlds\Response;

final readonly class WeightsTemplateOption extends TemplateOption
{
    /**
     * @param array<string, int> $defaultWeights
     * @param string[]           $validValues
     */
    public function __construct(
        string $key,
        string $description,
        public array $defaultWeights,
        public array $validValues,
    ) {
        parent::__construct($key, $description);
    }

    /** @param array<string, mixed> $data */
    public static function fromData(string $key, string $description, array $data): self
    {
        $defaultWeights = [];
        if (isset($data['defaultValue']) && is_array($data['defaultValue'])) {
            foreach ($data['defaultValue'] as $k => $v) {
                if (is_string($k) && is_int($v)) {
                    $defaultWeights[$k] = $v;
                }
            }
        }

        $validValues = [];
        if (isset($data['validValues']) && is_array($data['validValues'])) {
            $validValues = array_values(array_filter($data['validValues'], 'is_string'));
        }

        return new self($key, $description, $defaultWeights, $validValues);
    }
}
