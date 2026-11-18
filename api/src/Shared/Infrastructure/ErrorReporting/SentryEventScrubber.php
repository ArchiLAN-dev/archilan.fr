<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\ErrorReporting;

use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Story 33.32: what leaves for Bugsink. A client error (4xx) is not ours to fix and stays out; the request keeps its
 * method and path only (no headers, cookies, body or query string: tokens and secrets travel there); an e-mail
 * address in a message, an exception or the log context is masked.
 */
final class SentryEventScrubber
{
    private const string EMAIL = '/[\w.+-]+@[\w-]+\.[\w.-]+/u';

    public function __invoke(Event $event, ?EventHint $hint): ?Event
    {
        $exception = $hint?->exception;
        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
            return null;
        }

        $request = $event->getRequest();
        if ([] !== $request) {
            $url = $request['url'] ?? null;
            $event->setRequest(array_filter([
                'method' => $request['method'] ?? null,
                'url' => \is_string($url) ? strtok($url, '?') : null,
            ], static fn (mixed $value): bool => \is_string($value) && '' !== $value));
        }

        $message = $event->getMessage();
        if (null !== $message) {
            $event->setMessage(self::mask($message), $event->getMessageParams(), null === $event->getMessageFormatted() ? null : self::mask($event->getMessageFormatted()));
        }

        foreach ($event->getExceptions() as $data) {
            $data->setValue(self::mask($data->getValue()));
        }

        $event->setExtra(self::maskAll($event->getExtra()));

        return $event;
    }

    private static function mask(string $text): string
    {
        return (string) preg_replace(self::EMAIL, '[email]', $text);
    }

    /**
     * @template K of array-key
     *
     * @param array<K, mixed> $values
     *
     * @return array<K, mixed>
     */
    private static function maskAll(array $values): array
    {
        foreach ($values as $key => $value) {
            if (\is_string($value)) {
                $values[$key] = self::mask($value);
            } elseif (\is_array($value)) {
                $values[$key] = self::maskAll($value);
            }
        }

        return $values;
    }
}
