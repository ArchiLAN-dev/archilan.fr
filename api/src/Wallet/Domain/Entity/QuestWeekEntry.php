<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Entity;

use App\Wallet\Domain\Enum\QuestWeekOrigin;
use Doctrine\ORM\Mapping as ORM;

/**
 * A quest served in a week (story 41.15): pinned there by an admin, or drawn when the week was first needed. Once
 * there, it stays - editing or retiring the quest changes nothing to the weeks already served.
 */
#[ORM\Entity]
#[ORM\Table(name: 'quest_week_entry')]
#[ORM\UniqueConstraint(name: 'uniq_quest_week_entry', columns: ['week_key', 'quest_id'])]
final class QuestWeekEntry
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'week_key', type: 'string', length: 8)]
        private string $weekKey,
        #[ORM\Column(name: 'quest_id', type: 'string', length: 32)]
        private string $questId,
        #[ORM\Column(type: 'string', length: 8, enumType: QuestWeekOrigin::class)]
        private QuestWeekOrigin $origin,
        #[ORM\Column(type: 'integer')]
        private int $position,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public static function serve(string $weekKey, string $questId, QuestWeekOrigin $origin, int $position, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $weekKey, $questId, $origin, $position, $now);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getWeekKey(): string
    {
        return $this->weekKey;
    }

    public function getQuestId(): string
    {
        return $this->questId;
    }

    public function getOrigin(): QuestWeekOrigin
    {
        return $this->origin;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
