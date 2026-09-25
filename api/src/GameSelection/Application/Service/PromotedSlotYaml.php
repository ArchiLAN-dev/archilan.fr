<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Service;

use App\GameSelection\Application\Message\ApworldPromoted;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Enum\SlotYamlCase;
use App\GameSelection\Domain\Service\SlotYamlCompatibility;
use App\GameSelection\Domain\ValueObject\SlotYamlIssue;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * What an apworld promotion means for one player slot (story 38.7), shared by the personal runs and the
 * event registrations: which slots it concerns, and what becomes of their YAML.
 */
final readonly class PromotedSlotYaml
{
    /**
     * @param array<mixed>         $oldDefault
     * @param array<mixed>         $newDefault
     * @param array<string, mixed> $newTypes
     */
    private function __construct(
        public ApworldPromoted $promotion,
        public string $newDefaultYaml,
        private array $oldDefault,
        private array $newDefault,
        private array $newTypes,
    ) {
    }

    public static function for(ApworldPromoted $promotion, Game $game): self
    {
        $newDefaultYaml = $game->getDefaultYaml() ?? '';

        return new self(
            $promotion,
            $newDefaultYaml,
            self::parse($promotion->previousDefaultYaml) ?? [],
            self::parse($newDefaultYaml) ?? [],
            $game->getEffectiveOptionTypes() ?? [],
        );
    }

    /**
     * A slot of this game still on the version just replaced (or on none recorded). A slot on some
     * third version is not ours to move.
     */
    public function concerns(string $gameId, ?string $apworldHash): bool
    {
        if ($gameId !== $this->promotion->gameId || $apworldHash === $this->promotion->newHash) {
            return false;
        }

        return null === $apworldHash || '' === $apworldHash
            || null === $this->promotion->previousHash
            || $apworldHash === $this->promotion->previousHash;
    }

    /**
     * Never touched: the new default. Customised and still valid: kept byte for byte (never re-dumped:
     * a PHP re-dump loses the difference between an empty list and an empty mapping). Customised and no
     * longer valid, or unreadable: kept, with the reasons to review it.
     */
    public function decide(?string $playerYaml): PromotedSlotDecision
    {
        if (null === $playerYaml || '' === trim($playerYaml)) {
            $verdict = SlotYamlCompatibility::classify(null, $this->oldDefault, $this->newDefault, $this->newTypes);
        } else {
            $parsed = self::parse($playerYaml);
            $verdict = null === $parsed
                ? SlotYamlCompatibility::unreadable()
                : SlotYamlCompatibility::classify($parsed, $this->oldDefault, $this->newDefault, $this->newTypes);
        }

        return new PromotedSlotDecision(
            SlotYamlCase::ReplaceWithDefault === $verdict->case ? $this->newDefaultYaml : null,
            array_map(static fn (SlotYamlIssue $issue): string => $issue->message(), $verdict->issues),
        );
    }

    /**
     * @return array<mixed>|null null when absent or unreadable
     */
    private static function parse(?string $yaml): ?array
    {
        if (null === $yaml || '' === trim($yaml)) {
            return null;
        }
        try {
            $parsed = Yaml::parse(str_starts_with($yaml, "\u{FEFF}") ? substr($yaml, 3) : $yaml);
        } catch (ParseException) {
            return null;
        }

        return is_array($parsed) ? $parsed : null;
    }
}
