<?php

declare(strict_types=1);

namespace App\Community\Domain\Entity;

use App\Community\Domain\Enum\AchievementCounterKind;
use App\Community\Domain\Enum\AchievementCounterMode;
use Doctrine\ORM\Mapping as ORM;

/**
 * A named count over the session feed that achievements read as the fact `counter:{id}` (story 30.57): the checks
 * of some locations, or the copies of some items, of one game of the catalogue. Several achievements share one
 * counter (tiers 10 / 50 / 100). The names are exact AP names of that game, so a name shared with another game never
 * counts; the game is the catalogue's, tied to the feed by its Archipelago name.
 */
#[ORM\Entity]
#[ORM\Table(name: 'community_achievement_counter')]
final class AchievementCounter
{
    public const int MAX_LABEL = 80;

    public const int MAX_TARGETS = 50;

    public const int MAX_TARGET_LENGTH = 255;

    /**
     * @param list<string> $targets
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(type: 'string', length: 80)]
        private string $label,
        #[ORM\Column(name: 'game_id', type: 'string', length: 32)]
        private string $gameId,
        #[ORM\Column(type: 'string', length: 16, enumType: AchievementCounterKind::class)]
        private AchievementCounterKind $kind,
        #[ORM\Column(type: 'json')]
        private array $targets,
        #[ORM\Column(type: 'string', length: 16, enumType: AchievementCounterMode::class)]
        private AchievementCounterMode $mode,
        #[ORM\Column(name: 'with_others_only', type: 'boolean')]
        private bool $withOthersOnly,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param list<string> $targets
     *
     * @throws \InvalidArgumentException when the label, the game or the names are not valid
     */
    public static function create(
        string $id,
        string $label,
        string $gameId,
        AchievementCounterKind $kind,
        array $targets,
        AchievementCounterMode $mode,
        bool $withOthersOnly,
        \DateTimeImmutable $now,
    ): self {
        $counter = new self($id, '', '', $kind, [], $mode, $withOthersOnly, $now, $now);
        $counter->redefine($label, $gameId, $kind, $targets, $mode, $withOthersOnly, $now);

        return $counter;
    }

    /**
     * @param list<string> $targets
     *
     * @throws \InvalidArgumentException when the label, the game or the names are not valid
     */
    public function redefine(
        string $label,
        string $gameId,
        AchievementCounterKind $kind,
        array $targets,
        AchievementCounterMode $mode,
        bool $withOthersOnly,
        \DateTimeImmutable $now,
    ): void {
        $label = trim($label);
        if ('' === $label) {
            throw new \InvalidArgumentException('Le libellé du compteur est requis.');
        }
        if (mb_strlen($label) > self::MAX_LABEL) {
            throw new \InvalidArgumentException(sprintf('Le libellé fait %d caractères au plus.', self::MAX_LABEL));
        }
        $gameId = trim($gameId);
        if ('' === $gameId) {
            throw new \InvalidArgumentException('Le jeu du compteur est requis.');
        }

        $this->label = $label;
        $this->gameId = $gameId;
        $this->kind = $kind;
        $this->targets = self::normalizedTargets($targets);
        $this->mode = $mode;
        $this->withOthersOnly = $withOthersOnly;
        $this->updatedAt = $now;
    }

    /**
     * The names trimmed, without duplicates, in the order given: 1 to MAX_TARGETS of them. A name is kept as the
     * apworld spells it (case included), the feed matching it exactly.
     *
     * @param list<string> $targets
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException when there is no name, too many, or one too long
     */
    public static function normalizedTargets(array $targets): array
    {
        $names = [];
        foreach ($targets as $target) {
            $name = trim($target);
            if ('' === $name || \in_array($name, $names, true)) {
                continue;
            }
            if (mb_strlen($name) > self::MAX_TARGET_LENGTH) {
                throw new \InvalidArgumentException('Un nom de check ou d\'item est trop long.');
            }
            $names[] = $name;
        }
        if ([] === $names) {
            throw new \InvalidArgumentException('Le compteur vise au moins un check ou un item.');
        }
        if (\count($names) > self::MAX_TARGETS) {
            throw new \InvalidArgumentException(sprintf('Un compteur vise %d noms au plus.', self::MAX_TARGETS));
        }

        return $names;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getGameId(): string
    {
        return $this->gameId;
    }

    public function getKind(): AchievementCounterKind
    {
        return $this->kind;
    }

    /**
     * @return list<string>
     */
    public function getTargets(): array
    {
        return $this->targets;
    }

    public function getMode(): AchievementCounterMode
    {
        return $this->mode;
    }

    public function isWithOthersOnly(): bool
    {
        return $this->withOthersOnly;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
