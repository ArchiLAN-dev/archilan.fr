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
    private const array PUSHABLE_TYPES = ['slot_unblocked', 'run_invitation', 'friend_activity', 'run_nudge'];

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
            'run_invitation' => self::runInvitation($payload),
            'friend_activity' => self::friendActivity($payload),
            'run_nudge' => self::runNudge($payload),
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

        // Story 40.5: the progression of the unblocked slot; a notice from before it only knows the run.
        $slotIndex = self::text($payload, 'slotIndex');
        $url = match (true) {
            null === $runId => '/compte/parties',
            null === $slotIndex => '/runs/'.$runId,
            default => sprintf('/runs/%s/progression/%s', $runId, $slotIndex),
        };

        return new WebPushMessage(
            self::TITLE,
            $body,
            $url,
            sprintf('slot_unblocked-%s-%s', $runId ?? 'run', $slotName ?? 'slot'),
        );
    }

    /**
     * Story 43.1: a friend invites the member into a personal run; the push leads to « Mes parties », where the
     * invitation is answered.
     *
     * @param array<string, mixed> $payload
     */
    private static function runInvitation(array $payload): WebPushMessage
    {
        $inviter = self::text($payload, 'inviterName');
        $runTitle = self::text($payload, 'runTitle');
        $who = $inviter ?? 'Un ami';
        $body = null !== $runTitle
            ? sprintf('%s t\'invite dans « %s »', $who, $runTitle)
            : sprintf('%s t\'invite dans sa partie', $who);

        return new WebPushMessage(
            self::TITLE,
            $body,
            '/compte/parties',
            sprintf('run_invitation-%s', self::text($payload, 'invitationId') ?? 'invitation'),
        );
    }

    /**
     * Story 43.11b: a starred friend's activity, pushed only to a member who chose « Cloche + push ».
     *
     * @param array<string, mixed> $payload
     */
    private static function friendActivity(array $payload): WebPushMessage
    {
        $who = self::text($payload, 'actorName') ?? 'Un ami';
        $title = self::text($payload, 'title');
        $eventId = self::text($payload, 'eventId');
        $runId = self::text($payload, 'runId');
        $body = match (self::text($payload, 'kind')) {
            'registered' => null !== $title ? sprintf('%s s\'inscrit à « %s »', $who, $title) : sprintf('%s s\'inscrit à un événement', $who),
            'session_started' => null !== $title ? sprintf('%s lance « %s »', $who, $title) : sprintf('%s lance une partie', $who),
            default => null !== $title ? sprintf('%s a atteint son objectif dans « %s »', $who, $title) : sprintf('%s a atteint son objectif', $who),
        };
        $actorSlug = self::text($payload, 'actorSlug');
        // Story 43.19: the same place as the bell - the event, the run, else the friend's profile.
        $url = match (true) {
            null !== $eventId => '/evenements/'.$eventId,
            null !== $runId => '/runs/'.$runId,
            null !== $actorSlug => '/joueurs/'.$actorSlug,
            default => '/compte/amis',
        };

        return new WebPushMessage(
            self::TITLE,
            $body,
            $url,
            sprintf('friend_activity-%s-%s', self::text($payload, 'fromUserId') ?? 'friend', self::text($payload, 'kind') ?? 'activity'),
        );
    }

    /**
     * Story 43.12: a co-player of a personal run waits for the member's next session; the push leads to the run.
     *
     * @param array<string, mixed> $payload
     */
    private static function runNudge(array $payload): WebPushMessage
    {
        $who = self::text($payload, 'senderName') ?? 'Un co-joueur';
        $runTitle = self::text($payload, 'runTitle');
        $runId = self::text($payload, 'runId');
        $body = null !== $runTitle
            ? sprintf('%s attend ta prochaine session dans « %s »', $who, $runTitle)
            : sprintf('%s attend ta prochaine session', $who);

        return new WebPushMessage(
            self::TITLE,
            $body,
            null !== $runId ? '/runs/'.$runId : '/compte/parties',
            sprintf('run_nudge-%s', $runId ?? 'run'),
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
