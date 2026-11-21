<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Hotfix v1.0.3: when the worker last counted a week's quests, so a member with no count on the week reads zeros
 * rather than a count made during the request. Written with {@see QuestProgressCount}.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'quest_progress_run')]
final class QuestProgressRun
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'week_key', type: 'string', length: 8)]
        private string $weekKey,
        #[ORM\Column(name: 'counted_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $countedAt,
    ) {
    }

    public function getWeekKey(): string
    {
        return $this->weekKey;
    }

    public function getCountedAt(): \DateTimeImmutable
    {
        return $this->countedAt;
    }
}
