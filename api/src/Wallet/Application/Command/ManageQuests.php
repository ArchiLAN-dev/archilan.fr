<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use App\Wallet\Application\Support\QuestCalendar;
use App\Wallet\Domain\Entity\QuestDefinition;
use App\Wallet\Domain\Entity\QuestWeekEntry;
use App\Wallet\Domain\Enum\QuestMetric;
use App\Wallet\Domain\Enum\QuestWeekOrigin;
use App\Wallet\Domain\Repository\QuestRepositoryInterface;
use App\Wallet\Domain\ValueObject\QuestObjective;
use App\Wallet\Domain\ValueObject\QuestWeek;
use Psr\Clock\ClockInterface;

/**
 * The admin side of the weekly quests (story 41.15): write, edit, retire and restore a quest, set the number of
 * quests a week, pin a quest to the current or a coming week (in place of another one if asked), take one out.
 */
final readonly class ManageQuests
{
    public const int MIN_PER_WEEK = 1;
    public const int MAX_PER_WEEK = 10;

    public function __construct(
        private QuestRepositoryInterface $quests,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<mixed> $objectives raw `{metric, target}` rows
     *
     * @throws ValidationException when the text, reward or objectives are invalid
     */
    public function write(string $title, string $description, int $reward, array $objectives, bool $inDraw): WrittenQuest
    {
        $parsed = $this->objectives($objectives);
        try {
            $quest = QuestDefinition::write($title, $description, $reward, $parsed, $inDraw, $this->clock->now());
        } catch (\DomainException $e) {
            throw $this->invalid($e);
        }
        $this->quests->saveQuest($quest);

        return new WrittenQuest($quest->getId());
    }

    /**
     * @param array<mixed> $objectives raw `{metric, target}` rows
     *
     * @throws NotFoundException   when the quest does not exist
     * @throws ValidationException when the text, reward or objectives are invalid
     */
    public function edit(string $questId, string $title, string $description, int $reward, array $objectives, bool $inDraw): void
    {
        $quest = $this->quest($questId);
        $parsed = $this->objectives($objectives);
        try {
            $quest->edit($title, $description, $reward, $parsed, $inDraw);
        } catch (\DomainException $e) {
            throw $this->invalid($e);
        }
        $this->quests->saveQuest($quest);
    }

    /**
     * Retires the quest; its pins on weeks not drawn yet go with it, the weeks it was served in stay.
     *
     * @throws NotFoundException when the quest does not exist
     */
    public function retire(string $questId): void
    {
        $quest = $this->quest($questId);
        $quest->retire($this->clock->now());
        $this->quests->saveQuest($quest);

        $weeks = QuestCalendar::plannableKeys($this->clock->now());
        $drawn = $this->quests->drawnWeeks($weeks);
        foreach ($this->quests->entriesOfWeeks($weeks) as $weekKey => $entries) {
            if (\in_array($weekKey, $drawn, true)) {
                continue;
            }
            foreach ($entries as $entry) {
                if ($entry->getQuestId() === $questId) {
                    $this->quests->removeEntry($entry);
                }
            }
        }
    }

    /** @throws NotFoundException when the quest does not exist */
    public function restore(string $questId): void
    {
        $quest = $this->quest($questId);
        $quest->restore();
        $this->quests->saveQuest($quest);
    }

    /**
     * The settings of the weeks: the number of quests, and (story 41.16) the chest for doing them all. A null leaves
     * that setting as it is; both are checked before either changes.
     *
     * @throws ValidationException when a value is out of bounds
     */
    public function changeSettings(?int $questsPerWeek, ?int $chestReward): void
    {
        if (null !== $questsPerWeek && ($questsPerWeek < self::MIN_PER_WEEK || $questsPerWeek > self::MAX_PER_WEEK)) {
            throw new ValidationException(sprintf('De %d à %d quêtes par semaine.', self::MIN_PER_WEEK, self::MAX_PER_WEEK), ['questsPerWeek' => ['Nombre invalide.']], 'quests_per_week_invalid');
        }
        if (null !== $chestReward && ($chestReward < 0 || $chestReward > QuestDefinition::MAX_REWARD)) {
            throw new ValidationException(sprintf('Un coffre de 0 à %d pelles (0 : pas de coffre).', QuestDefinition::MAX_REWARD), ['chestReward' => ['Montant invalide.']], 'quest_chest_reward_invalid');
        }
        if (null !== $questsPerWeek) {
            $this->quests->changeQuestsPerWeek($questsPerWeek);
        }
        if (null !== $chestReward) {
            $this->quests->changeChestReward($chestReward);
        }
    }

    /**
     * Pins the quest to the week, at the place of `$replaces` when given (that quest leaves the week).
     *
     * @throws ValidationException when the week is past or unknown
     * @throws NotFoundException   when the quest, or the one to replace in that week, does not exist
     * @throws ConflictException   when the quest is retired or already in the week
     */
    public function pin(string $weekKey, string $questId, ?string $replaces = null): void
    {
        $week = $this->openWeek($weekKey);
        $quest = $this->quest($questId);
        if ($quest->isRetired()) {
            throw new ConflictException('Une quête retirée ne s\'épingle plus.', 'quest_retired');
        }
        $entries = $this->quests->entriesOfWeeks([$week->key])[$week->key] ?? [];
        foreach ($entries as $entry) {
            if ($entry->getQuestId() === $questId) {
                throw new ConflictException('Cette quête est déjà dans la semaine.', 'quest_already_in_week');
            }
        }

        $position = [] === $entries ? 0 : max(array_map(static fn (QuestWeekEntry $entry): int => $entry->getPosition(), $entries)) + 1;
        if (null !== $replaces) {
            $replaced = $this->entry($entries, $replaces);
            $position = $replaced->getPosition();
            $this->quests->removeEntry($replaced);
        }
        $this->quests->saveEntry(QuestWeekEntry::serve($week->key, $questId, QuestWeekOrigin::Pinned, $position, $this->clock->now()));
    }

    /**
     * @throws ValidationException when the week is past or unknown
     * @throws NotFoundException   when the quest is not in the week
     */
    public function unpin(string $weekKey, string $questId): void
    {
        $week = $this->openWeek($weekKey);
        $this->quests->removeEntry($this->entry($this->quests->entriesOfWeeks([$week->key])[$week->key] ?? [], $questId));
    }

    /**
     * @param array<mixed> $rows
     *
     * @return list<QuestObjective>
     */
    private function objectives(array $rows): array
    {
        $objectives = [];
        foreach ($rows as $row) {
            $metric = \is_array($row) && \is_string($row['metric'] ?? null) ? QuestMetric::tryFrom($row['metric']) : null;
            $target = \is_array($row) && \is_int($row['target'] ?? null) ? $row['target'] : 0;
            if (null === $metric) {
                throw new ValidationException('Objectif inconnu.', ['objectives' => ['Type d\'objectif inconnu.']], 'quest_metric_unknown');
            }
            try {
                $objectives[] = new QuestObjective($metric, $target);
            } catch (\DomainException $e) {
                throw $this->invalid($e);
            }
        }

        return $objectives;
    }

    private function invalid(\DomainException $e): ValidationException
    {
        $message = match ($e->getMessage()) {
            'quest_text_invalid' => sprintf('Un titre de 1 à %d caractères, une description de %d au plus.', QuestDefinition::MAX_TITLE, QuestDefinition::MAX_DESCRIPTION),
            'quest_reward_invalid' => sprintf('Une récompense de %d à %d pelles.', QuestDefinition::MIN_REWARD, QuestDefinition::MAX_REWARD),
            'quest_objectives_invalid' => sprintf('De 1 à %d objectifs.', QuestDefinition::MAX_OBJECTIVES),
            'quest_objectives_duplicated' => 'Chaque type d\'objectif une fois au plus.',
            'quest_objective_target_invalid' => sprintf('Une cible de %d à %d.', QuestObjective::MIN_TARGET, QuestObjective::MAX_TARGET),
            default => 'Quête invalide.',
        };

        return new ValidationException($message, [], $e->getMessage());
    }

    private function quest(string $questId): QuestDefinition
    {
        return $this->quests->findQuest($questId) ?? throw new NotFoundException('Quête introuvable.', 'quest_not_found');
    }

    /**
     * @param list<QuestWeekEntry> $entries
     */
    private function entry(array $entries, string $questId): QuestWeekEntry
    {
        foreach ($entries as $entry) {
            if ($entry->getQuestId() === $questId) {
                return $entry;
            }
        }

        throw new NotFoundException('Cette quête n\'est pas dans la semaine.', 'quest_not_in_week');
    }

    /** The current week or one of the coming ones the admin page shows: a past week is history. */
    private function openWeek(string $weekKey): QuestWeek
    {
        $week = QuestWeek::fromKey($weekKey);
        if (null === $week || !\in_array($week->key, QuestCalendar::plannableKeys($this->clock->now()), true)) {
            throw new ValidationException('Semaine passée, trop lointaine ou inconnue.', ['week' => ['Semaine invalide.']], 'quest_week_closed');
        }

        return $week;
    }
}
