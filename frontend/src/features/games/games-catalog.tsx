"use client";

import { useEffect, useMemo, useState } from "react";
import { Gamepad2, Search } from "lucide-react";
import { GameCard } from "./game-card";
import { SteamCoupling } from "./steam-coupling";
import { useSteamCoupling } from "./use-steam-coupling";
import {
  allCategories,
  filterAndSortGames,
  isOwned,
  type AvailabilityFilter,
  type SortOrder,
} from "./games-filter";
import type { PublicGame } from "./public-games-api";

export function GamesCatalog({ initialGames }: { initialGames: PublicGame[] }) {
  const { matchedAppIds, coupled, couplingProps } = useSteamCoupling();

  const [query, setQuery] = useState("");
  const debouncedQuery = useDebouncedValue(query, 150);
  const [availability, setAvailability] = useState<AvailabilityFilter>("all");
  const [ownedOnly, setOwnedOnly] = useState(false);
  const [sort, setSort] = useState<SortOrder>("name-asc");
  const [categories, setCategories] = useState<string[]>([]);
  const categoryOptions = useMemo(() => allCategories(initialGames), [initialGames]);

  // Drop the owned-only filter if a coupling is cleared/unsuccessful.
  useEffect(() => {
    if (!coupled && ownedOnly) {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setOwnedOnly(false);
    }
  }, [coupled, ownedOnly]);

  const visibleGames = useMemo(
    () =>
      filterAndSortGames(
        initialGames,
        { query: debouncedQuery, availability, ownedOnly, sort, categories },
        matchedAppIds,
      ),
    [initialGames, debouncedQuery, availability, ownedOnly, sort, categories, matchedAppIds],
  );

  function toggleCategory(category: string) {
    setCategories((prev) =>
      prev.includes(category) ? prev.filter((c) => c !== category) : [...prev, category],
    );
  }

  return (
    <div className="grid gap-8">
      <SteamCoupling {...couplingProps} />

      <CatalogControls
        availability={availability}
        coupled={coupled}
        ownedOnly={ownedOnly}
        query={query}
        sort={sort}
        onAvailability={setAvailability}
        onOwnedOnly={setOwnedOnly}
        onQuery={setQuery}
        onSort={setSort}
      />

      {categoryOptions.length > 0 ? (
        <CategoryChips options={categoryOptions} selected={categories} onToggle={toggleCategory} />
      ) : null}

      <p className="text-sm text-muted-foreground" role="status">
        {visibleGames.length} jeu{visibleGames.length !== 1 ? "x" : ""}
        {coupled ? ` · ${visibleGames.filter((g) => isOwned(g, matchedAppIds)).length} possédé(s)` : ""}
      </p>

      {visibleGames.length > 0 ? (
        <div className="grid gap-5 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
          {visibleGames.map((game) => (
            <GameCard game={game} key={game.id} owned={isOwned(game, matchedAppIds)} />
          ))}
        </div>
      ) : (
        <div className="card-glow rounded-lg border border-border p-10 text-center">
          <Gamepad2 aria-hidden="true" className="mx-auto mb-4 size-10 text-accent-text" />
          <p className="font-heading text-xl font-semibold text-foreground">Aucun jeu trouvé</p>
          <p className="mx-auto mt-3 max-w-md text-sm leading-6 text-muted-foreground">
            Ajuste ta recherche ou tes filtres.
          </p>
        </div>
      )}
    </div>
  );
}

// ── Sub-components ────────────────────────────────────────────────────────────

function CatalogControls({
  query,
  availability,
  ownedOnly,
  sort,
  coupled,
  onQuery,
  onAvailability,
  onOwnedOnly,
  onSort,
}: {
  query: string;
  availability: AvailabilityFilter;
  ownedOnly: boolean;
  sort: SortOrder;
  coupled: boolean;
  onQuery: (v: string) => void;
  onAvailability: (v: AvailabilityFilter) => void;
  onOwnedOnly: (v: boolean) => void;
  onSort: (v: SortOrder) => void;
}) {
  const selectClass =
    "min-h-11 rounded border border-border bg-background px-3 text-sm text-foreground outline-none transition-colors focus:border-accent";

  return (
    <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
      <div className="relative max-w-md flex-1">
        <Search
          aria-hidden="true"
          className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
        />
        <input
          aria-label="Rechercher un jeu"
          className="min-h-11 w-full rounded border border-border bg-background py-2 pl-10 pr-4 text-sm outline-none transition-colors focus:border-accent"
          onChange={(e) => onQuery(e.target.value)}
          placeholder="Hollow Knight, Stardew Valley…"
          type="search"
          value={query}
        />
      </div>

      <select
        aria-label="Filtrer par disponibilité"
        className={selectClass}
        onChange={(e) => onAvailability(e.target.value as AvailabilityFilter)}
        value={availability}
      >
        <option value="all">Toutes dispos</option>
        <option value="available">Disponible</option>
        <option value="experimental">Expérimental</option>
      </select>

      <select
        aria-label="Trier"
        className={selectClass}
        onChange={(e) => onSort(e.target.value as SortOrder)}
        value={sort}
      >
        <option value="name-asc">Nom A→Z</option>
        <option value="name-desc">Nom Z→A</option>
      </select>

      <label
        className={`inline-flex min-h-11 items-center gap-2 rounded border border-border px-3 text-sm font-medium ${coupled ? "text-foreground" : "cursor-not-allowed text-muted-foreground/50"}`}
        title={coupled ? undefined : "Couple ta bibliothèque Steam d'abord"}
      >
        <input
          checked={ownedOnly}
          className="size-4 accent-accent"
          disabled={!coupled}
          onChange={(e) => onOwnedOnly(e.target.checked)}
          type="checkbox"
        />
        Mes jeux
      </label>
    </div>
  );
}

function CategoryChips({
  options,
  selected,
  onToggle,
}: {
  options: string[];
  selected: string[];
  onToggle: (category: string) => void;
}) {
  return (
    <div className="flex flex-wrap gap-2" role="group" aria-label="Filtrer par catégorie">
      {options.map((category) => {
        const active = selected.includes(category);
        return (
          <button
            key={category}
            aria-pressed={active}
            className={`inline-flex min-h-9 items-center rounded-full border px-3 text-sm font-medium transition-colors ${
              active
                ? "border-accent bg-accent/15 text-accent-text"
                : "border-border bg-surface text-muted-foreground hover:border-accent hover:text-foreground"
            }`}
            onClick={() => onToggle(category)}
            type="button"
          >
            {category}
          </button>
        );
      })}
    </div>
  );
}

// ── Hooks ─────────────────────────────────────────────────────────────────────

function useDebouncedValue<T>(value: T, delayMs: number): T {
  const [debounced, setDebounced] = useState(value);

  useEffect(() => {
    const id = setTimeout(() => setDebounced(value), delayMs);
    return () => clearTimeout(id);
  }, [value, delayMs]);

  return debounced;
}
