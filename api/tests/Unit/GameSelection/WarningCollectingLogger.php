<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Keeps the warning messages, drops the rest (story 38.2 tests).
 */
final class WarningCollectingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $warnings = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        if (LogLevel::WARNING === $level) {
            $this->warnings[] = (string) $message;
        }
    }
}
