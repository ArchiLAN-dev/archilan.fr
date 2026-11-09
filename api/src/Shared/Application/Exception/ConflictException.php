<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

/**
 * The request conflicts with the current state of the resource (e.g. a uniqueness clash). Maps to HTTP 409.
 */
final class ConflictException extends \RuntimeException implements ApplicationFailure
{
    use ApplicationFailureTrait;

    /**
     * @param array<string, mixed> $details what the client needs to resolve the conflict (story 52.4: the new price)
     */
    public function __construct(string $message, string $errorCode = 'conflict', array $details = [])
    {
        parent::__construct($message);
        $this->failureMessage = $message;
        $this->failureCode = $errorCode;
        $this->failureDetails = $details;
    }

    public function statusCode(): int
    {
        return 409;
    }
}
