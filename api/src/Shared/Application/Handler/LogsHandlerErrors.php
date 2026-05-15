<?php

declare(strict_types=1);

namespace App\Shared\Application\Handler;

use Psr\Log\LoggerInterface;

/**
 * @property LoggerInterface $logger
 */
trait LogsHandlerErrors
{
    protected function executeWithLogging(string $context, \Closure $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->logger->error($context, ['exception' => $e]);
            throw $e;
        }
    }
}
