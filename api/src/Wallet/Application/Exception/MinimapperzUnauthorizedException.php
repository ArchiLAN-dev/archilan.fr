<?php

declare(strict_types=1);

namespace App\Wallet\Application\Exception;

/** Story 49.1: Minimapperz refused the key for this event (wrong key, wrong slug, or revoked). Never carries the key. */
final class MinimapperzUnauthorizedException extends \RuntimeException
{
}
