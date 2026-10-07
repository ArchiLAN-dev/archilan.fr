<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

use App\Shared\Application\Exception\BadGatewayException;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Wallet\Application\Exception\QuestAnnouncementDeliveryException;
use App\Wallet\Application\Port\QuestAnnouncementChannelInterface;
use App\Wallet\Application\Query\WeeklyQuestsQueryInterface;
use App\Wallet\Application\Service\QuestWeekPlanner;
use App\Wallet\Application\Support\QuestAnnouncement;
use App\Wallet\Domain\Entity\QuestDefinition;
use App\Wallet\Domain\Repository\QuestRepositoryInterface;
use App\Wallet\Domain\ValueObject\QuestObjective;
use App\Wallet\Domain\ValueObject\QuestWeek;

/**
 * Tells a week's quests on Discord (story 41.24), through the bot (story 41.26): the week's message is updated
 * rather than posted twice. Run by the Monday job, or by an admin who wants it now (a test, a retry after a failure).
 */
final readonly class AnnounceQuestsOnDiscord
{
    public function __construct(
        private QuestWeekPlanner $planner,
        private QuestRepositoryInterface $settings,
        private WeeklyQuestsQueryInterface $quests,
        private QuestAnnouncementChannelInterface $channel,
        private string $siteUrl,
    ) {
    }

    /**
     * @throws ConflictException   when Discord is not configured, or the week serves no quest
     * @throws NotFoundException   when the week key is not a week
     * @throws BadGatewayException when Discord refused or could not be reached
     */
    public function announce(string $weekKey): QuestDiscordAnnouncement
    {
        if (!$this->channel->isEnabled()) {
            throw new ConflictException('Discord n\'est pas configuré pour les quêtes (DISCORD_QUESTS_CHANNEL_ID, DISCORD_BOT_TOKEN).', 'discord_not_configured');
        }
        $week = QuestWeek::fromKey($weekKey);
        if (null === $week) {
            throw new NotFoundException('Semaine introuvable.', 'quest_week_not_found');
        }
        $served = $this->planner->served($week);
        if ([] === $served) {
            throw new ConflictException('Cette semaine n\'a pas de quête à annoncer.', 'quest_week_empty');
        }

        $announcement = new QuestAnnouncement(
            $week->key,
            $this->inWords($served),
            $this->settings->chestReward(),
            $week->end,
            rtrim($this->siteUrl, '/').'/compte/portefeuille',
        );

        $previous = $this->settings->discordMessageOf($week->key);
        try {
            $messageId = $this->channel->publish($announcement, $previous);
        } catch (QuestAnnouncementDeliveryException $e) {
            throw new BadGatewayException(sprintf('Discord n\'a pas pris l\'annonce : %s', $e->getMessage()), 'discord_announce_failed');
        }
        $this->settings->rememberDiscordMessage($week->key, $messageId);

        return $previous === $messageId ? QuestDiscordAnnouncement::Updated : QuestDiscordAnnouncement::Posted;
    }

    /**
     * Each quest with its objectives in words, « 50 checks et 1 partie sur Hollow Knight ».
     *
     * @param list<QuestDefinition> $served
     *
     * @return list<array{title: string, objectives: string, reward: int}>
     */
    private function inWords(array $served): array
    {
        $names = $this->scopeNames($served);

        return array_map(static fn (QuestDefinition $quest): array => [
            'title' => $quest->getTitle(),
            'objectives' => implode(' et ', array_map(
                static fn (QuestObjective $objective): string => QuestAnnouncement::describe($objective, $names[$objective->key()] ?? null),
                $quest->getObjectives(),
            )),
            'reward' => $quest->getReward(),
        ], $served);
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
