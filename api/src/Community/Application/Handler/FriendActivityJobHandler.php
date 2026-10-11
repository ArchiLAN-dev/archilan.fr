<?php

declare(strict_types=1);

namespace App\Community\Application\Handler;

use App\Community\Application\Message\FriendActivityJob;
use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Application\Query\FriendActivitySourceQueryInterface;
use App\Community\Application\Query\FriendSessionContextQueryInterface;
use App\Community\Application\Support\Notifier;
use App\Community\Domain\Entity\Notification;
use App\Community\Domain\Enum\PresenceVisibility;
use App\Community\Domain\Repository\CommunityProfileRepositoryInterface;
use App\Community\Domain\Repository\FriendFavoriteRepositoryInterface;
use App\Community\Domain\Repository\FriendshipRepositoryInterface;
use App\Community\Domain\Service\AudiencePolicy;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Story 43.11b: tells the members who starred someone what they just did - registered to an event to come, launched
 * an event session, reached a goal.
 *
 * - The actor's presence setting (43.6) comes first: who hides their presence from friends is never announced.
 * - A member already playing the session is not told about it.
 * - A personal run launch waits for the runs open to friends (43.14): only event sessions are announced.
 * - Anti-noise: one alert per job and recipient (several starred friends launching together make one alert), at
 *   most one alert naming the same friend per hour, at most ten a day. An alert the recipient turned off is never
 *   saved, so it does not count.
 */
#[AsMessageHandler]
final readonly class FriendActivityJobHandler
{
    public const int DAILY_CAP = 10;
    private const string PER_FRIEND_WINDOW = '-1 hour';
    private const string DAILY_WINDOW = '-24 hours';

    public function __construct(
        private FriendActivitySourceQueryInterface $source,
        private FriendFavoriteRepositoryInterface $favorites,
        private CommunityProfileRepositoryInterface $profiles,
        private CommunityUserDirectoryQueryInterface $directory,
        private FriendSessionContextQueryInterface $sessions,
        private Notifier $notifier,
        private ClockInterface $clock,
        private FriendshipRepositoryInterface $friendships,
    ) {
    }

    public function __invoke(FriendActivityJob $job): void
    {
        $now = $this->clock->now();
        $eventTitle = null;
        $participants = [];

        switch ($job->kind) {
            case FriendActivityJob::REGISTERED:
                $eventTitle = $this->source->upcomingPublicEventTitle($job->contextId, $now);
                if (null === $eventTitle || null === $job->actorId) {
                    return;
                }
                $actors = [$job->actorId];
                break;
            case FriendActivityJob::SESSION_STARTED:
                $actors = $participants = $this->source->sessionPlayers($job->contextId);
                break;
            case FriendActivityJob::GOAL_REACHED:
                if (null === $job->slotName) {
                    return;
                }
                $actors = $this->source->slotPlayers($job->contextId, $job->slotName);
                $participants = $this->source->sessionPlayers($job->contextId);
                break;
            default:
                return;
        }

        $cards = $this->directory->cards($actors);
        $actorsByRecipient = [];
        foreach ($actors as $actorId) {
            if (!isset($cards[$actorId]) || !$this->announces($actorId)) {
                continue;
            }
            foreach ($this->favorites->starredBy($actorId) as $recipientId) {
                // Story 43.19: a star left behind by an ended friendship never sends an alert.
                if (!in_array($recipientId, $participants, true) && $this->friendships->areFriends($recipientId, $actorId)) {
                    $actorsByRecipient[$recipientId][] = $actorId;
                }
            }
        }

        foreach ($actorsByRecipient as $recipientId => $recipientActors) {
            if (count($this->source->alertActorsSince($recipientId, $now->modify(self::DAILY_WINDOW))) >= self::DAILY_CAP) {
                continue;
            }
            $hourAgo = $now->modify(self::PER_FRIEND_WINDOW);
            $fresh = array_values(array_filter(
                $recipientActors,
                fn (string $actorId): bool => !$this->source->hasAlertFromSince($recipientId, $actorId, $hourAgo),
            ));
            if ([] === $fresh) {
                continue;
            }

            if (FriendActivityJob::REGISTERED === $job->kind) {
                $where = ['title' => $eventTitle, 'eventId' => $job->contextId, 'runId' => null];
            } else {
                $context = $this->sessions->forViewer([$job->contextId], $recipientId)[$job->contextId] ?? null;
                // No context: a weekly run, which presence never shows either.
                // Story 43.19: a personal run is announced when it was open to friends (43.11b AC1, with 43.14).
                if (null === $context || (FriendActivityJob::SESSION_STARTED === $job->kind && 'event' !== $context['kind']
                    && !$this->source->isRunOpenToFriends($job->contextId))) {
                    continue;
                }
                $where = ['title' => $context['title'], 'eventId' => $context['eventId'], 'runId' => $context['runId']];
            }

            $actorId = $fresh[0];
            $card = $cards[$actorId];
            $this->notifier->notify($recipientId, Notification::TYPE_FRIEND_ACTIVITY, [
                'fromUserId' => $actorId,
                'actorName' => $card['displayName'] ?? $card['slug'],
                // Story 43.19: the push leads to the friend's profile when there is no event or run to open.
                'actorSlug' => $card['slug'],
                'kind' => $job->kind,
                ...$where,
                'others' => count($fresh) - 1,
                // Story 43.19: every favourite this alert covers, for the per-favourite hourly cap.
                'actorIds' => $fresh,
            ]);
        }
    }

    /** A friend may see the actor's presence (43.6): only « personne » hides it from them. */
    private function announces(string $actorId): bool
    {
        $visibility = $this->profiles->findByUserId($actorId)?->getPresenceVisibility() ?? PresenceVisibility::DEFAULT;

        return $visibility->shows(AudiencePolicy::TIER_FRIEND);
    }
}
