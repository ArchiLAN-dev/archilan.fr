<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

/**
 * The caller is authenticated but not allowed to act on the resource (e.g. not the owner). Maps to HTTP 403.
 */
final class ForbiddenException extends \RuntimeException implements ApplicationFailure
{
    use ApplicationFailureTrait;

    public function __construct(string $message, string $errorCode = 'forbidden')
    {
        parent::__construct($message);
        $this->failureMessage = $message;
        $this->failureCode = $errorCode;
    }

    public function statusCode(): int
    {
        return 403;
    }
}
