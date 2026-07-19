<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

/**
 * A dependency the command needs is unavailable (e.g. object storage down). Maps to HTTP 503.
 */
final class ServiceUnavailableException extends \RuntimeException implements ApplicationFailure
{
    use ApplicationFailureTrait;

    public function __construct(string $message, string $errorCode = 'service_unavailable')
    {
        parent::__construct($message);
        $this->failureMessage = $message;
        $this->failureCode = $errorCode;
    }

    public function statusCode(): int
    {
        return 503;
    }
}
