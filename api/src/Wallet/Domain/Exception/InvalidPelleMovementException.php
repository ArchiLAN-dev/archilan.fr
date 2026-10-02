<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Exception;

/**
 * Raised when a pelles movement is malformed (story 41.1): zero amount, blank label, event pelles without
 * their event or gold pelles tied to one.
 */
final class InvalidPelleMovementException extends \RuntimeException
{
}
