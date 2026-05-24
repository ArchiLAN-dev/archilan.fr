<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Sessions\Yaml\Option;

interface OptionValue extends \JsonSerializable
{
    public function getKey(): string;
}
