<?php

declare(strict_types=1);

namespace App\Tests\Unit\CatalogSync;

use Psr\Log\AbstractLogger;

/**
 * Records every log line by level and message (story 14.11 tests).
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $logs = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->logs[] = ['level' => is_string($level) ? $level : 'unknown', 'message' => (string) $message];
    }
}
