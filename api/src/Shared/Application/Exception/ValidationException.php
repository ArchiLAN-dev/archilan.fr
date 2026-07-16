<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

/**
 * Input failed validation. Maps to HTTP 422; the field map is surfaced under `error.details`.
 */
final class ValidationException extends \RuntimeException implements ApplicationFailure
{
    use ApplicationFailureTrait;

    /**
     * @param array<string, mixed> $details field map, e.g. ['email' => ['Adresse invalide.']]
     */
    public function __construct(string $message, array $details = [], string $errorCode = 'validation_failed')
    {
        parent::__construct($message);
        $this->failureMessage = $message;
        $this->failureCode = $errorCode;
        $this->failureDetails = $details;
    }

    public function statusCode(): int
    {
        return 422;
    }
}
