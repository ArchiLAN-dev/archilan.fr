<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Apworlds\Response;

final readonly class TextTemplateOption extends TemplateOption
{
    public function __construct(
        string $key,
        string $description,
        public ?string $default,
    ) {
        parent::__construct($key, $description);
    }

    /** @param array<string, mixed> $data */
    public static function fromData(string $key, string $description, array $data): self
    {
        $default = is_string($data['defaultValue'] ?? null) ? $data['defaultValue'] : null;

        return new self($key, $description, $default);
    }
}
