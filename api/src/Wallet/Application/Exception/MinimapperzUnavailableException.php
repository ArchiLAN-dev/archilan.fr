<?php

declare(strict_types=1);

namespace App\Wallet\Application\Exception;

/**
 * Story 49.1: Minimapperz could not be read (network error, timeout, unexpected status or body). Worth retrying.
 * Never carries the key.
 */
final class MinimapperzUnavailableException extends \RuntimeException
{
}
