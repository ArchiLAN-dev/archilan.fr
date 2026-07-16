<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

/**
 * The request conflicts with the current state of the resource (e.g. a uniqueness clash). Maps to HTTP 409.
 */
final class ConflictException extends \RuntimeException implements ApplicationFailure
{
    use ApplicationFailureTrait;

    public function __construct(string $message, string $errorCode = 'conflict')
    {
        parent::__construct($message);
        $this->failureMessage = $message;
        $this->failureCode = $errorCode;
    }

    public function statusCode(): int
    {
        return 409;
    }
}
