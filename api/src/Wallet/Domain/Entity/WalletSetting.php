<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A setting of the pelles the admins tune from the site (story 41.15: the number of quests a week).
 */
#[ORM\Entity]
#[ORM\Table(name: 'wallet_setting')]
final class WalletSetting
{
    public const string QUESTS_PER_WEEK = 'quests_per_week';
    // Story 41.16: the weekly chest, for accomplishing every quest of the week.
    public const string QUEST_CHEST_REWARD = 'quest_chest_reward';
    // Story 41.17: the last week whose quests were announced to the members.
    public const string QUESTS_ANNOUNCED_WEEK = 'quests_announced_week';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'setting_key', type: 'string', length: 64)]
        private string $key,
        #[ORM\Column(type: 'string', length: 255)]
        private string $value,
    ) {
    }

    public function change(string $value): void
    {
        $this->value = $value;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): string
    {
        return $this->value;
    }
}
