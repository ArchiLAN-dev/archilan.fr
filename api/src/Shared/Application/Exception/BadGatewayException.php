<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

/**
 * An upstream dependency the command relies on returned a failure (e.g. the mailer refused to send).
 * Maps to HTTP 502.
 */
final class BadGatewayException extends \RuntimeException implements ApplicationFailure
{
    use ApplicationFailureTrait;

    public function __construct(string $message, string $errorCode = 'bad_gateway')
    {
        parent::__construct($message);
        $this->failureMessage = $message;
        $this->failureCode = $errorCode;
    }

    public function statusCode(): int
    {
        return 502;
    }
}
