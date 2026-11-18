<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\ErrorReporting;

use App\Shared\Application\Exception\ApplicationFailure;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Story 33.32: what leaves for Bugsink.
 *
 * - A client error (4xx) is not ours to fix and stays out: Symfony's own HTTP exceptions and the application's typed
 *   failures (epic 35), which Symfony's ErrorListener still logs at CRITICAL after they became a JSON response.
 * - The request keeps its method and path only: headers, cookies, body and query string carry tokens and secrets.
 * - Stack frames lose their variables (function arguments: a password, a token, an e-mail).
 * - An e-mail address is masked wherever text remains: message and its parameters, exceptions, extras, tags,
 *   breadcrumbs. An object left in the log context is reduced to its class name, before the SDK spells out its
 *   properties.
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
        if ($exception instanceof ApplicationFailure && $exception->statusCode() < 500) {
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
            $formatted = $event->getMessageFormatted();
            $event->setMessage(self::mask($message), array_map(self::mask(...), $event->getMessageParams()), null === $formatted ? null : self::mask($formatted));
        }

        foreach ($event->getExceptions() as $data) {
            $data->setValue(self::mask($data->getValue()));
            foreach ($data->getStacktrace()?->getFrames() ?? [] as $frame) {
                $frame->setVars([]);
            }
        }

        $event->setExtra(self::maskAll($event->getExtra()));
        $event->setTags(array_map(self::mask(...), $event->getTags()));
        $event->setBreadcrumb(array_map(self::scrubBreadcrumb(...), $event->getBreadcrumbs()));

        return $event;
    }

    private static function scrubBreadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        $message = $breadcrumb->getMessage();
        $scrubbed = null === $message ? $breadcrumb : $breadcrumb->withMessage(self::mask($message));
        foreach (array_keys($breadcrumb->getMetadata()) as $name) {
            $scrubbed = $scrubbed->withoutMetadata($name);
        }

        return $scrubbed;
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
            } elseif (\is_object($value)) {
                $values[$key] = $value::class;
            }
        }

        return $values;
    }
}
