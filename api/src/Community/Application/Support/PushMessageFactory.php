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
    private const array PUSHABLE_TYPES = [
        'slot_unblocked', 'run_invitation', 'friend_activity', 'run_nudge',
        // Story 43.28: the moments of a personal run (pushed by default only when it starts, see the preferences).
        'run_joined', 'run_games_chosen', 'run_started', 'run_recap_ready',
        // Story 16.23.
        'run_all_ready',
        // Story 38.22 (pushed only for a member who asks, see the preferences).
        'game_apworld_updated',
    ];

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
            'run_joined', 'run_games_chosen', 'run_started', 'run_recap_ready', 'run_all_ready' => self::runMoment($type, $payload),
            'game_apworld_updated' => self::gameApworldUpdated($payload),
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
     * Story 43.28: a player joined the member's run or chose their games, the run started, its recap is ready. The
     * push leads to the run, where the recap shows once it is over.
     *
     * @param array<string, mixed> $payload
     */
    private static function runMoment(string $type, array $payload): WebPushMessage
    {
        $runId = self::text($payload, 'runId');
        $run = self::text($payload, 'runTitle');
        $quoted = null !== $run ? sprintf('« %s »', $run) : null;
        $body = match ($type) {
            'run_joined' => sprintf('%s a rejoint %s', self::text($payload, 'joinerName') ?? 'Un joueur', $quoted ?? 'ta partie'),
            'run_games_chosen' => self::gamesChosen($payload, $quoted),
            'run_started' => null !== $quoted ? sprintf('%s est lancée : à toi de jouer', $quoted) : 'Ta partie est lancée : à toi de jouer',
            'run_all_ready' => null !== $quoted ? sprintf('Tout le monde est prêt dans %s : tu peux lancer', $quoted) : 'Tout le monde est prêt : tu peux lancer ta partie',
            default => null !== $quoted ? sprintf('Le récap de %s est prêt', $quoted) : 'Le récap de ta partie est prêt',
        };

        return new WebPushMessage(
            self::TITLE,
            $body,
            null !== $runId ? '/runs/'.$runId : '/compte/parties',
            sprintf('%s-%s-%s', $type, $runId ?? 'run', self::text($payload, 'fromUserId') ?? 'all'),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function gameApworldUpdated(array $payload): WebPushMessage
    {
        $game = self::text($payload, 'gameName') ?? 'Un jeu suivi';
        $version = self::text($payload, 'version');
        $body = true === ($payload['first'] ?? false)
            ? sprintf('%s est jouable sur le site', $game)
            : sprintf('Nouvel apworld pour %s%s', $game, null !== $version ? ' ('.$version.')' : '');
        $slug = self::text($payload, 'gameSlug');

        return new WebPushMessage(
            self::TITLE,
            $body,
            null !== $slug ? '/jeux/'.$slug : '/jeux',
            sprintf('game_apworld_updated-%s-%s', self::text($payload, 'gameId') ?? 'game', $version ?? 'new'),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function gamesChosen(array $payload, ?string $quoted): string
    {
        $who = self::text($payload, 'playerName') ?? 'Un joueur';
        $games = $payload['games'] ?? null;
        $names = is_array($games) ? array_values(array_filter($games, is_string(...))) : [];
        $where = null !== $quoted ? ' dans '.$quoted : '';

        return [] === $names
            ? sprintf('%s a choisi ses jeux%s', $who, $where)
            : sprintf('%s jouera %s%s', $who, implode(', ', $names), $where);
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
