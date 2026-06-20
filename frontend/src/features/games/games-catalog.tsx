"use client";

import { useEffect, useMemo, useState } from "react";
import { Gamepad2, Search } from "lucide-react";
import { GameCard } from "./game-card";
import { SteamCoupling } from "./steam-coupling";
import { useSteamCoupling } from "./use-steam-coupling";
import { FilterTokenBar, type ActiveFilterToken, type FilterGroup } from "./filter-token-bar";
import {
  allCategories,
  filterAndSortGames,
  isOwned,
  type AvailabilityFilter,
  type SortOrder,
} from "./games-filter";
import type { PublicGame } from "./public-games-api";

const availabilityLabels: Record<Exclude<AvailabilityFilter, "all">, string> = {
  available: "Disponible",
  experimental: "Expérimental",
};

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

  // ── Token filters (availability + owned + categories), cumulable via a single picker ──
  const addFilter = (value: string) => {
    if ("avail:available" === value) setAvailability("available");
    else if ("avail:experimental" === value) setAvailability("experimental");
    else if ("__owned" === value) setOwnedOnly(true);
    else if (value.startsWith("cat:")) {
      const category = value.slice(4);
      setCategories((prev) => (prev.includes(category) ? prev : [...prev, category]));
    }
  };

  const filterGroups: FilterGroup[] = [
    {
      label: "Disponibilité",
      options: (["available", "experimental"] as const)
        .filter((a) => availability !== a)
        .map((a) => ({ value: `avail:${a}`, label: availabilityLabels[a] })),
    },
    {
      label: "Filtres",
      options: coupled && !ownedOnly ? [{ value: "__owned", label: "Mes jeux" }] : [],
    },
    {
      label: "Plateformes",
      options: categoryOptions
        .filter((c) => !categories.includes(c))
        .map((c) => ({ value: `cat:${c}`, label: c })),
    },
  ];

  const activeTokens: ActiveFilterToken[] = [];
  if (availability !== "all") {
    activeTokens.push({ key: "avail", label: availabilityLabels[availability], remove: () => setAvailability("all") });
  }
  if (ownedOnly) {
    activeTokens.push({ key: "owned", label: "Mes jeux", icon: "gamepad", remove: () => setOwnedOnly(false) });
  }
  for (const category of categories) {
    activeTokens.push({
      key: `cat:${category}`,
      label: category,
      remove: () => setCategories((prev) => prev.filter((c) => c !== category)),
    });
  }

  const hasActiveFilters = query.trim() !== "" || availability !== "all" || ownedOnly || categories.length > 0;
  const clearFilters = () => {
    setQuery("");
    setAvailability("all");
    setOwnedOnly(false);
    setCategories([]);
  };

  return (
    <div className="grid gap-8">
      <SteamCoupling {...couplingProps} />

      <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
        <div className="relative max-w-md flex-1">
          <Search
            aria-hidden="true"
            className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
          />
          <input
            aria-label="Rechercher un jeu"
            className="min-h-11 w-full rounded border border-border bg-background py-2 pl-10 pr-4 text-sm outline-none transition-colors focus:border-accent"
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Hollow Knight, Stardew Valley…"
            type="search"
            value={query}
          />
        </div>

        <select
          aria-label="Trier"
          className="min-h-11 rounded border border-border bg-background px-3 text-sm text-foreground outline-none transition-colors focus:border-accent"
          onChange={(e) => setSort(e.target.value as SortOrder)}
          value={sort}
        >
          <option value="name-asc">Nom A→Z</option>
          <option value="name-desc">Nom Z→A</option>
        </select>
      </div>

      <FilterTokenBar
        activeTokens={activeTokens}
        groups={filterGroups}
        hasActiveFilters={hasActiveFilters}
        onAdd={addFilter}
        onClear={clearFilters}
      />

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

// ── Hooks ─────────────────────────────────────────────────────────────────────

function useDebouncedValue<T>(value: T, delayMs: number): T {
  const [debounced, setDebounced] = useState(value);

  useEffect(() => {
    const id = setTimeout(() => setDebounced(value), delayMs);
    return () => clearTimeout(id);
  }, [value, delayMs]);

  return debounced;
}
