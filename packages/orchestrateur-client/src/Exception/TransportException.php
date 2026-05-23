<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Exception;

final class TransportException extends OrchestratorException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
