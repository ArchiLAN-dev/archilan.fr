<?php

declare(strict_types=1);

namespace App\Wallet\Application\Handler;

use App\Wallet\Application\Message\AnnounceQuestsOnDiscordJob;
use App\Wallet\Application\Port\QuestAnnouncementChannelInterface;
use App\Wallet\Application\Query\WeeklyQuestsQueryInterface;
use App\Wallet\Application\Service\QuestWeekPlanner;
use App\Wallet\Application\Support\QuestAnnouncement;
use App\Wallet\Domain\Entity\QuestDefinition;
use App\Wallet\Domain\Repository\QuestRepositoryInterface;
use App\Wallet\Domain\ValueObject\QuestObjective;
use App\Wallet\Domain\ValueObject\QuestWeek;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Story 41.24: tells the week's quests on Discord. A failure is logged and left there - the site already announced
 * the week (41.17), and a late or repeated Discord message would do more harm than a missing one.
 */
#[AsMessageHandler]
final readonly class AnnounceQuestsOnDiscordJobHandler
{
    public function __construct(
        private QuestWeekPlanner $planner,
        private QuestRepositoryInterface $settings,
        private WeeklyQuestsQueryInterface $quests,
        private QuestAnnouncementChannelInterface $channel,
        private LoggerInterface $logger,
        private string $siteUrl,
    ) {
    }

    public function __invoke(AnnounceQuestsOnDiscordJob $job): void
    {
        $week = QuestWeek::fromKey($job->weekKey);
        $served = null === $week ? [] : $this->planner->served($week);
        if (null === $week || [] === $served) {
            return;
        }

        $names = $this->scopeNames($served);
        $announcement = new QuestAnnouncement(
            $week->key,
            array_map(static fn (QuestDefinition $quest): array => [
                'title' => $quest->getTitle(),
                'objectives' => implode(' et ', array_map(
                    static fn (QuestObjective $objective): string => self::describe($objective, $names[$objective->key()] ?? null),
                    $quest->getObjectives(),
                )),
                'reward' => $quest->getReward(),
            ], $served),
            $this->settings->chestReward(),
            $week->end,
            rtrim($this->siteUrl, '/').'/compte/portefeuille',
        );

        try {
            $this->channel->post($announcement);
        } catch (\RuntimeException $e) {
            $this->logger->warning('wallet.quests.discord_failed', ['week' => $week->key, 'reason' => $e->getMessage()]);
        }
    }

    /** « 2 goals sur Hollow Knight », « 50 checks » - an objective in words. */
    public static function describe(QuestObjective $objective, ?string $scopeName): string
    {
        $text = sprintf('%d %s', $objective->target, $objective->metric->unitFor($objective->target));
        if (null === $objective->scope) {
            return $text;
        }

        return sprintf(QuestObjective::SCOPE_GAME === $objective->scope ? '%s sur %s' : '%s à %s', $text, $scopeName ?? 'une cible retirée');
    }

    /**
     * @param list<QuestDefinition> $quests
     *
     * @return array<string, string> objective key => name of its game or event
     */
    private function scopeNames(array $quests): array
    {
        $aimed = array_filter(QuestDefinition::objectivesOf($quests), static fn (QuestObjective $objective): bool => null !== $objective->scope);
        if ([] === $aimed) {
            return [];
        }
        $options = $this->quests->scopeOptions();
        $names = [
            QuestObjective::SCOPE_GAME => array_column($options['games'], 'name', 'id'),
            QuestObjective::SCOPE_EVENT => array_column($options['events'], 'title', 'id'),
        ];
        $scopes = [];
        foreach ($aimed as $objective) {
            $name = $names[(string) $objective->scope][(string) $objective->scopeId] ?? null;
            if (null !== $name) {
                $scopes[$objective->key()] = $name;
            }
        }

        return $scopes;
    }
}
