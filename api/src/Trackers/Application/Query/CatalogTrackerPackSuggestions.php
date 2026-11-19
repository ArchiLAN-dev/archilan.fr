<?php

declare(strict_types=1);

namespace App\Trackers\Application\Query;

use App\Trackers\Application\Port\TrackerCatalogInterface;
use App\Trackers\Domain\ValueObject\CatalogPackLink;

/**
 * Story 54.12: the PopTracker packs the community catalog names for a game, minus the ones already imported. Only
 * proposed: nothing is imported without the admin.
 */
final readonly class CatalogTrackerPackSuggestions
{
    public function __construct(
        private TrackerCatalogInterface $catalog,
        private AdminTrackerPackQueryInterface $packs,
    ) {
    }

    /** @return list<CatalogTrackerPackSuggestion>|null null for an unknown game */
    public function forGame(string $gameId): ?array
    {
        $links = $this->catalog->linksForGame($gameId);
        if (null === $links) {
            return null;
        }
        $imported = array_map(static fn (AdminTrackerPackView $pack): string => $pack->sourceUrl, $this->packs->forGame($gameId));

        return self::suggestions($links, $imported);
    }

    /** @return list<CatalogTrackerPackGame> the games with an importable pack to propose, by name */
    public function gamesToEquip(): array
    {
        $imported = $this->packs->sourceUrlsByGame();
        $games = [];
        foreach ($this->catalog->allGames() as $game) {
            $importable = array_values(array_filter(
                self::suggestions($game['links'], $imported[$game['gameId']] ?? []),
                static fn (CatalogTrackerPackSuggestion $suggestion): bool => null !== $suggestion->importUrl,
            ));
            if ([] !== $importable) {
                $games[] = new CatalogTrackerPackGame($game['gameId'], $game['gameName'], $game['gameSlug'], $importable);
            }
        }

        return $games;
    }

    /**
     * @param list<array{label: string, url: ?string}> $links
     * @param list<string>                             $importedSources
     *
     * @return list<CatalogTrackerPackSuggestion>
     */
    private static function suggestions(array $links, array $importedSources): array
    {
        $suggestions = [];
        $seen = [];
        foreach ($links as $link) {
            $pack = CatalogPackLink::fromCatalog($link['label'], $link['url']);
            if (null === $pack) {
                continue;
            }
            foreach ($importedSources as $source) {
                if ($pack->sameRepository($source)) {
                    continue 2;
                }
            }
            $key = mb_strtolower($pack->importUrl() ?? $pack->url);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $suggestions[] = new CatalogTrackerPackSuggestion(
                $pack->label,
                $pack->url,
                $pack->importUrl(),
                null !== $pack->owner && null !== $pack->repo ? $pack->owner.'/'.$pack->repo : null,
            );
        }

        return $suggestions;
    }
}
