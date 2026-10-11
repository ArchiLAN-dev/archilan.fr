<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Support;

/**
 * One run listing as told on Discord (story 43.27): only what the listings page shows a visitor (story 43.24), never
 * the friends in it nor anything about an account.
 */
final readonly class RunListingAnnouncement
{
    public const int MAX_GAMES = 5;

    /**
     * @param list<string> $games
     */
    public function __construct(
        public string $runId,
        public string $title,
        public string $hostName,
        public string $pitch,
        public ?\DateTimeImmutable $plannedFor,
        public ?int $seatsWanted,
        public int $joined,
        public array $games,
        public string $url,
    ) {
    }

    /**
     * Mirror of the listing card's `seatsLeftLabel` (story 43.25).
     */
    public function seatsLabel(): string
    {
        if (null === $this->seatsWanted) {
            return 0 === $this->joined
                ? 'Places illimitées'
                : sprintf('%d inscrit%s · places illimitées', $this->joined, $this->joined > 1 ? 's' : '');
        }
        $left = max($this->seatsWanted - $this->joined, 0);
        $plural = $left > 1 ? 's' : '';

        return sprintf('%d place%s libre%s sur %d', $left, $plural, $plural, $this->seatsWanted);
    }

    /** The games, five at most then « +N », as on the card. */
    public function gamesLabel(): ?string
    {
        if ([] === $this->games) {
            return null;
        }
        $shown = \array_slice($this->games, 0, self::MAX_GAMES);
        $more = \count($this->games) - \count($shown);

        return implode(', ', $shown).($more > 0 ? sprintf(' +%d', $more) : '');
    }

    /** What the message says: two announcements with the same fingerprint render the same message. */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->title,
            $this->hostName,
            $this->pitch,
            $this->plannedFor?->getTimestamp(),
            $this->seatsLabel(),
            $this->gamesLabel(),
            $this->url,
        ], \JSON_THROW_ON_ERROR));
    }
}
