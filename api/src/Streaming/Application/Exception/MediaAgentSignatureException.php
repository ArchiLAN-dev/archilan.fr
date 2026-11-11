<?php

declare(strict_types=1);

namespace App\Streaming\Application\Exception;

use App\Shared\Application\Exception\ApplicationFailure;
use App\Shared\Application\Exception\ApplicationFailureTrait;

/** Story 47.6: a régie agent report whose signature or timestamp does not hold. Maps to HTTP 401. */
final class MediaAgentSignatureException extends \RuntimeException implements ApplicationFailure
{
    use ApplicationFailureTrait;

    public function __construct()
    {
        parent::__construct('Signature invalide.');
        $this->failureMessage = 'Signature invalide.';
        $this->failureCode = 'invalid_signature';
    }

    public function statusCode(): int
    {
        return 401;
    }
}
