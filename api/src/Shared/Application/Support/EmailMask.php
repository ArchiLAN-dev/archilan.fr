<?php

declare(strict_types=1);

namespace App\Shared\Application\Support;

/**
 * An address as it may appear in the logs (story 50.3): enough to tell two members apart while reading them, not
 * enough to write to one. The record itself is found by its id, which the log lines carry.
 */
final class EmailMask
{
    public static function mask(string $email): string
    {
        $at = strrpos($email, '@');
        if (false === $at || 0 === $at) {
            return '***';
        }

        return mb_substr($email, 0, 1).'***'.substr($email, $at);
    }
}
