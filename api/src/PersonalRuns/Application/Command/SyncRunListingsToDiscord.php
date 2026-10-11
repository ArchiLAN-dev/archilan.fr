<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Command;

use App\PersonalRuns\Application\Exception\RunListingDeliveryException;
use App\PersonalRuns\Application\Port\RunListingChannelInterface;
use App\PersonalRuns\Application\Query\OpenRunListingsQuery;
use App\PersonalRuns\Application\Support\RunListingAnnouncement;
use App\PersonalRuns\Domain\Entity\RunListingPost;
use App\PersonalRuns\Domain\Repository\RunListingPostRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Makes the Discord channel say what the listings page says (story 43.27): a message per listing online, posted,
 * rewritten when it changed, deleted once the listing is gone.
 *
 * A listing leaves the page by many roads (launched, cancelled, closed, full, expired, owner sanctioned); comparing the
 * page with the posted messages catches every one of them instead of hooking each. The listings are read as a visitor
 * sees them (story 43.24), so no friend or account detail can reach the channel.
 *
 * The role is mentioned once per run, ever: a reposted message (deleted on Discord, or a listing back online) stays
 * quiet. One failing listing never stops the others; the next pass retries it.
 */
final readonly class SyncRunListingsToDiscord
{
    public const string LISTINGS_PATH = '/communaute/parties';

    public function __construct(
        private OpenRunListingsQuery $listings,
        private RunListingPostRepositoryInterface $posts,
        private RunListingChannelInterface $channel,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private string $siteUrl,
    ) {
    }

    public function sync(): RunListingsSyncResult
    {
        if (!$this->channel->isEnabled()) {
            return new RunListingsSyncResult(0, 0, 0, 0);
        }

        $posts = $this->posts->all();
        $posted = $edited = $deleted = $failed = 0;

        $online = [];
        foreach ($this->listings->forViewer(null) as $listing) {
            $announcement = $this->announcement($listing);
            if (!$announcement instanceof RunListingAnnouncement) {
                continue;
            }
            $online[$announcement->runId] = true;
            $post = $posts[$announcement->runId] ?? RunListingPost::open($announcement->runId, $this->clock->now());

            try {
                $outcome = $this->tell($post, $announcement);
            } catch (RunListingDeliveryException $e) {
                ++$failed;
                $this->logger->warning('personal_run.listing_discord.failed', ['runId' => $announcement->runId, 'error' => $e->getMessage()]);

                continue;
            }
            if ('posted' === $outcome) {
                ++$posted;
            } elseif ('edited' === $outcome) {
                ++$edited;
            }
        }

        foreach ($posts as $runId => $post) {
            $messageId = $post->getMessageId();
            if (isset($online[$runId]) || null === $messageId) {
                continue;
            }
            try {
                $this->channel->delete($messageId);
            } catch (RunListingDeliveryException $e) {
                ++$failed;
                $this->logger->warning('personal_run.listing_discord.failed', ['runId' => $runId, 'error' => $e->getMessage()]);

                continue;
            }
            $post->withdrawn($this->clock->now());
            $this->posts->save($post);
            ++$deleted;
        }

        return new RunListingsSyncResult($posted, $edited, $deleted, $failed);
    }

    /**
     * @return 'posted'|'edited'|'unchanged'
     *
     * @throws RunListingDeliveryException
     */
    private function tell(RunListingPost $post, RunListingAnnouncement $announcement): string
    {
        $fingerprint = $announcement->fingerprint();
        if ($post->says($fingerprint)) {
            return 'unchanged';
        }

        $messageId = $post->getMessageId();
        if (null !== $messageId && $this->channel->edit($messageId, $announcement)) {
            $post->posted($messageId, $fingerprint, false, $this->clock->now());
            $this->posts->save($post);

            return 'edited';
        }

        $sent = $this->channel->post($announcement, $post->mayMention());
        $post->posted($sent['messageId'], $fingerprint, $sent['mentioned'], $this->clock->now());
        $this->posts->save($post);

        return 'posted';
    }

    /**
     * @param array<string, mixed> $listing a row of OpenRunListingsQuery, visitor view
     */
    private function announcement(array $listing): ?RunListingAnnouncement
    {
        $runId = $listing['runId'] ?? null;
        $title = $listing['title'] ?? null;
        $pitch = $listing['pitch'] ?? null;
        $owner = $listing['owner'] ?? null;
        if (!is_string($runId) || !is_string($title) || !is_string($pitch) || !is_array($owner)) {
            return null;
        }
        $host = $owner['displayName'] ?? null;
        if (!is_string($host) || '' === $host) {
            $host = $owner['slug'] ?? null;
        }
        if (!is_string($host)) {
            return null;
        }
        $plannedFor = $listing['plannedFor'] ?? null;
        $seats = $listing['seatsWanted'] ?? null;
        $joined = $listing['joined'] ?? 0;
        $games = $listing['games'] ?? [];

        return new RunListingAnnouncement(
            $runId,
            $title,
            $host,
            $pitch,
            is_string($plannedFor) ? new \DateTimeImmutable($plannedFor) : null,
            is_int($seats) ? $seats : null,
            is_int($joined) ? $joined : 0,
            is_array($games) ? array_values(array_filter($games, is_string(...))) : [],
            rtrim($this->siteUrl, '/').self::LISTINGS_PATH,
        );
    }
}
