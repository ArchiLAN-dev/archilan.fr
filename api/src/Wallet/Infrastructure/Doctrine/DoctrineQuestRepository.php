<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Doctrine;

use App\Wallet\Domain\Entity\QuestDefinition;
use App\Wallet\Domain\Entity\QuestWeekDraw;
use App\Wallet\Domain\Entity\QuestWeekEntry;
use App\Wallet\Domain\Entity\WalletSetting;
use App\Wallet\Domain\Repository\QuestRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The weekly quests' storage (story 41.15).
 */
final readonly class DoctrineQuestRepository implements QuestRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findQuest(string $id): ?QuestDefinition
    {
        return $this->entityManager->find(QuestDefinition::class, $id);
    }

    public function allQuests(): array
    {
        return $this->entityManager->getRepository(QuestDefinition::class)->findBy([], ['createdAt' => 'DESC']);
    }

    public function saveQuest(QuestDefinition $quest): void
    {
        $this->entityManager->persist($quest);
        $this->entityManager->flush();
    }

    public function entriesOfWeeks(array $weekKeys): array
    {
        $byWeek = array_fill_keys($weekKeys, []);
        if ([] === $weekKeys) {
            return $byWeek;
        }
        $entries = $this->entityManager->getRepository(QuestWeekEntry::class)->findBy(['weekKey' => $weekKeys], ['position' => 'ASC', 'createdAt' => 'ASC']);
        foreach ($entries as $entry) {
            $byWeek[$entry->getWeekKey()][] = $entry;
        }

        return $byWeek;
    }

    public function saveEntry(QuestWeekEntry $entry): void
    {
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
    }

    public function removeEntry(QuestWeekEntry $entry): void
    {
        $this->entityManager->remove($entry);
        $this->entityManager->flush();
    }

    public function claimDraw(string $weekKey, \DateTimeImmutable $now): bool
    {
        // The primary key arbitrates two first readers of the same week: only one insert lands.
        $inserted = $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO quest_week_draw (week_key, drawn_at) VALUES (:week, :now) ON CONFLICT (week_key) DO NOTHING',
            ['week' => $weekKey, 'now' => $now->format(\DATE_ATOM)],
        );

        return 1 === $inserted;
    }

    public function drawnWeeks(array $weekKeys): array
    {
        if ([] === $weekKeys) {
            return [];
        }

        return array_map(
            static fn (QuestWeekDraw $draw): string => $draw->getWeekKey(),
            $this->entityManager->getRepository(QuestWeekDraw::class)->findBy(['weekKey' => $weekKeys]),
        );
    }

    public function weeksServedByQuest(): array
    {
        $weeks = [];
        foreach ($this->entityManager->getRepository(QuestWeekEntry::class)->findBy([], ['weekKey' => 'ASC']) as $entry) {
            $weeks[$entry->getQuestId()][] = $entry->getWeekKey();
        }

        return $weeks;
    }

    public function questsPerWeek(): int
    {
        return $this->intSetting(WalletSetting::QUESTS_PER_WEEK, self::DEFAULT_QUESTS_PER_WEEK);
    }

    public function changeQuestsPerWeek(int $count): void
    {
        $this->changeSetting(WalletSetting::QUESTS_PER_WEEK, $count);
    }

    public function chestReward(): int
    {
        return $this->intSetting(WalletSetting::QUEST_CHEST_REWARD, self::DEFAULT_CHEST_REWARD);
    }

    public function changeChestReward(int $reward): void
    {
        $this->changeSetting(WalletSetting::QUEST_CHEST_REWARD, $reward);
    }

    public function announcedWeek(): ?string
    {
        return $this->entityManager->find(WalletSetting::class, WalletSetting::QUESTS_ANNOUNCED_WEEK)?->getValue();
    }

    public function markAnnounced(string $weekKey): void
    {
        $this->changeSetting(WalletSetting::QUESTS_ANNOUNCED_WEEK, $weekKey);
    }

    private function intSetting(string $key, int $default): int
    {
        $setting = $this->entityManager->find(WalletSetting::class, $key);
        $value = null === $setting ? false : filter_var($setting->getValue(), \FILTER_VALIDATE_INT);

        return false === $value ? $default : $value;
    }

    private function changeSetting(string $key, int|string $value): void
    {
        $setting = $this->entityManager->find(WalletSetting::class, $key);
        if (null === $setting) {
            $this->entityManager->persist(new WalletSetting($key, (string) $value));
        } else {
            $setting->change((string) $value);
        }
        $this->entityManager->flush();
    }
}
