<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Apworlds\Response;

use Archilan\OrchestratorClient\Exception\OrchestratorException;

final readonly class UploadApworldResult
{
    /**
     * @param TemplateOption[] $options
     */
    public function __construct(
        public string $hash,
        public array $options,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $hash = $data['hash'] ?? null;
        if (!is_string($hash)) {
            throw new OrchestratorException("Missing or invalid field 'hash' in apworld upload response");
        }

        $rawOptions = $data['options'] ?? [];
        $options = [];
        if (is_array($rawOptions)) {
            foreach ($rawOptions as $opt) {
                if (is_array($opt)) {
                    /** @var array<string, mixed> $opt */
                    $options[] = TemplateOption::fromArray($opt);
                }
            }
        }

        return new self(hash: $hash, options: $options);
    }
}
