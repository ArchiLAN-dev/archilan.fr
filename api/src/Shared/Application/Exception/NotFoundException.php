<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

/**
 * The addressed resource does not exist (or is not visible to the caller). Maps to HTTP 404.
 */
final class NotFoundException extends \RuntimeException implements ApplicationFailure
{
    use ApplicationFailureTrait;

    public function __construct(string $message, string $errorCode = 'not_found')
    {
        parent::__construct($message);
        $this->failureMessage = $message;
        $this->failureCode = $errorCode;
    }

    public function statusCode(): int
    {
        return 404;
    }
}
