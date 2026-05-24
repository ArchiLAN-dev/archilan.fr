<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Exception;

final class ConflictException extends OrchestratorException
{
    public function __construct(
        public readonly string $errorCode,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(sprintf('Conflict: %s', $errorCode), 0, $previous);
    }
}
