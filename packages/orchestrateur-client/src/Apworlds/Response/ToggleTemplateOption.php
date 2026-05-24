<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Apworlds\Response;

final readonly class ToggleTemplateOption extends TemplateOption
{
    /**
     * @param array<string, int> $weights keys are 'true' and 'false'
     */
    public function __construct(
        string $key,
        string $description,
        public bool $default,
        public array $weights = [],
    ) {
        parent::__construct($key, $description);
    }

    /** @param array<string, mixed> $data */
    public static function fromData(string $key, string $description, array $data): self
    {
        $default = isset($data['defaultValue']) && true === $data['defaultValue'];
        $weights = [];
        if (isset($data['weights']) && is_array($data['weights'])) {
            foreach ($data['weights'] as $k => $v) {
                if (is_string($k) && is_int($v)) {
                    $weights[$k] = $v;
                }
            }
        }

        return new self($key, $description, $default, $weights);
    }
}
