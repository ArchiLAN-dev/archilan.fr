<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

/**
 * Which notifications of the site also go out as a browser push, and their text (story 40.2). An explicit
 * list: a new notification type is not pushed until someone decides it is worth interrupting for. The text
 * mirrors the bell (`notification-center.tsx`), since a push has no front to render it.
 */
final class PushMessageFactory
{
    private const string TITLE = 'ArchiLAN';

    /** @var list<string> */
    private const array PUSHABLE_TYPES = ['slot_unblocked'];

    public static function isPushable(string $type): bool
    {
        return in_array($type, self::PUSHABLE_TYPES, true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function forNotification(string $type, array $payload): ?WebPushMessage
    {
        return match ($type) {
            'slot_unblocked' => self::slotUnblocked($payload),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function slotUnblocked(array $payload): WebPushMessage
    {
        $runId = self::text($payload, 'runId');
        $runTitle = self::text($payload, 'runTitle');
        $slotName = self::text($payload, 'slotName');
        $count = $payload['reachableNow'] ?? null;

        if (null === $runTitle) {
            $body = 'Tu n\'es plus bloqué dans ta partie';
        } else {
            $slot = null !== $slotName ? sprintf(' (%s)', $slotName) : '';
            $checks = is_int($count) ? sprintf(' : %d %s', $count, $count > 1 ? 'checks accessibles' : 'check accessible') : '';
            $body = sprintf('Tu n\'es plus bloqué dans « %s »%s%s', $runTitle, $slot, $checks);
        }

        return new WebPushMessage(
            self::TITLE,
            $body,
            null !== $runId ? '/runs/'.$runId : '/compte/parties',
            sprintf('slot_unblocked-%s-%s', $runId ?? 'run', $slotName ?? 'slot'),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function text(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && '' !== $value ? $value : null;
    }
}
