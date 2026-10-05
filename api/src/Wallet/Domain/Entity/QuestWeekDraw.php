<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Marks a week whose quests were drawn (story 41.15): the draw happens once, the first time the week is needed.
 * Its primary key is the lock - the repository claims it with an insert that only one caller wins.
 */
#[ORM\Entity]
#[ORM\Table(name: 'quest_week_draw')]
final class QuestWeekDraw
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'week_key', type: 'string', length: 8)]
        private string $weekKey,
        #[ORM\Column(name: 'drawn_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $drawnAt,
    ) {
    }

    public function getWeekKey(): string
    {
        return $this->weekKey;
    }

    public function getDrawnAt(): \DateTimeImmutable
    {
        return $this->drawnAt;
    }
}
