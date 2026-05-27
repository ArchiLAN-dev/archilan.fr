<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure;

use Archilan\OrchestratorClient\Sessions\Yaml\Option\OptionValue;

final readonly class RawOptionValue implements OptionValue
{
    public function __construct(private string $key, private mixed $value)
    {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function jsonSerialize(): mixed
    {
        return $this->value;
    }
}
