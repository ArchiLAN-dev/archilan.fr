<?php

declare(strict_types=1);

namespace App\Wallet\Application\Exception;

use App\Shared\Application\Exception\ApplicationFailure;
use App\Shared\Application\Exception\ApplicationFailureTrait;

/**
 * A credit from the blind test app whose signature or timestamp does not hold (story 41.40). Maps to HTTP 401.
 */
final class BlindTestSignatureException extends \RuntimeException implements ApplicationFailure
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
