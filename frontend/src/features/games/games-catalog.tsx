"use client";

import { useEffect, useMemo, useState } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { Gamepad2, Search } from "lucide-react";
import { GameCard } from "./game-card";
import { SteamCoupling } from "./steam-coupling";
import { useSteamCoupling } from "./use-steam-coupling";
import { useGameList } from "./use-game-list";
import { FilterTokenBar, type ActiveFilterToken, type FilterGroup } from "./filter-token-bar";
import {
  allCategories,
  filterAndSortGames,
  isOwned,
  type AvailabilityFilter,
  type ListFilter,
  type SortOrder,
} from "./games-filter";
import { filtersToQueryString, readFiltersFromParams } from "./catalog-url-filters";
import type { PublicGame } from "./public-games-api";

const availabilityLabels: Record<Exclude<AvailabilityFilter, "all">, string> = {
  available: "Disponible",
  experimental: "Expérimental",
};

const listLabels: Record<Exclude<ListFilter, "all">, string> = {
  owned: "Mes jeux",
  planned: "À essayer",
};

export function GamesCatalog({ initialGames }: { initialGames: PublicGame[] }) {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();

  // Story 28.12: the URL *is* the filter state, so a browser back after opening a game restores
  // the list exactly as it was. Read once at mount; written back below.
  const [initial] = useState(() => readFiltersFromParams(searchParams));

  const [query, setQuery] = useState(initial.query);
  const debouncedQuery = useDebouncedValue(query, 150);
  const [availability, setAvailability] = useState<AvailabilityFilter>(initial.availability);
  const [list, setList] = useState<ListFilter>(initial.list);

  // Story 28.11: coupling a library *is* the request to see what you own - so an explicit
  // coupling turns the filter on. The automatic pre-fill on each load never does.
  const { matchedAppIds, coupled, settled, couplingProps } = useSteamCoupling({
    onExplicitCouple: () => setList("owned"),
  });
  // Story 28.13: the ArchiLAN-side list, unioned with the Steam one. It is the only way to claim a
  // game with no steamAppId - a GameCube or SNES title will never match a coupled library.
  const { gameIds: ownedGameIds } = useGameList("owned");
  // Story 28.14: the second list, read on its own. It never feeds `isOwned` - wanting a game must
  // not make the catalog claim you have it.
  const { gameIds: plannedGameIds, settled: plannedSettled } = useGameList("planned");
  const hasAnyOwnership = coupled || ownedGameIds.size > 0;
  const hasPlanned = plannedGameIds.size > 0;
  const [sort, setSort] = useState<SortOrder>(initial.sort);
  const [categories, setCategories] = useState<string[]>(initial.categories);
  const categoryOptions = useMemo(() => allCategories(initialGames), [initialGames]);

  // Drop a list filter whose list turns out to hold nothing: a cleared or unsuccessful Steam
  // coupling for "mes jeux", a shared `?liste=a-essayer` opened by someone whose own list is empty
  // or who is signed out. Each waits for its own source to settle - at mount the coupling attempt
  // is still in flight and the list query has not answered, and clearing early would wipe the
  // filter the URL had just restored (story 28.12).
  useEffect(() => {
    const strandedOnOwned = settled && !hasAnyOwnership && "owned" === list;
    const strandedOnPlanned = plannedSettled && !hasPlanned && "planned" === list;
    if (strandedOnOwned || strandedOnPlanned) {
      // eslint-disable-next-line react-hooks/set-state-in-effect -- reset the filter in reaction to an external source (Steam coupling cleared, list empty) resolving; guarded so it fires once per transition
      setList("all");
    }
  }, [settled, hasAnyOwnership, plannedSettled, hasPlanned, list]);

  // Mirror the filters into the URL. `replace` rather than `push`: filtering must not make the back
  // button walk backwards through every keystroke, while the entry still carries the latest state.
  // Follows the debounced query, not the raw input.
  useEffect(() => {
    const queryString = filtersToQueryString({
      availability,
      categories,
      list,
      query: debouncedQuery,
      sort,
    });
    const next = "" === queryString ? pathname : `${pathname}?${queryString}`;
    if (next !== `${pathname}${window.location.search}`) {
      router.replace(next, { scroll: false });
    }
  }, [debouncedQuery, availability, list, sort, categories, pathname, router]);

  const visibleGames = useMemo(
    () =>
      filterAndSortGames(
        initialGames,
        { query: debouncedQuery, availability, list, sort, categories },
        matchedAppIds,
        ownedGameIds,
        plannedGameIds,
      ),
    [
      initialGames,
      debouncedQuery,
      availability,
      list,
      sort,
      categories,
      matchedAppIds,
      ownedGameIds,
      plannedGameIds,
    ],
  );

  // ── Token filters (availability + list + categories), cumulable via a single picker, except
  // that the two list tokens replace each other rather than adding up (story 28.14) ──
  const addFilter = (value: string) => {
    if ("avail:available" === value) setAvailability("available");
    else if ("avail:experimental" === value) setAvailability("experimental");
    else if ("list:owned" === value) setList("owned");
    else if ("list:planned" === value) setList("planned");
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
      label: "Mes listes",
      options: [
        ...(hasAnyOwnership && "owned" !== list ? [{ value: "list:owned", label: listLabels.owned }] : []),
        ...(hasPlanned && "planned" !== list ? [{ value: "list:planned", label: listLabels.planned }] : []),
      ],
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
  if ("all" !== list) {
    activeTokens.push({
      key: `list:${list}`,
      label: listLabels[list],
      icon: "owned" === list ? "gamepad" : "bookmark",
      remove: () => setList("all"),
    });
  }
  for (const category of categories) {
    activeTokens.push({
      key: `cat:${category}`,
      label: category,
      remove: () => setCategories((prev) => prev.filter((c) => c !== category)),
    });
  }

  const hasActiveFilters =
    query.trim() !== "" || availability !== "all" || list !== "all" || categories.length > 0;
  const clearFilters = () => {
    setQuery("");
    setAvailability("all");
    setList("all");
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
        {hasAnyOwnership
          ? ` · ${visibleGames.filter((g) => isOwned(g, matchedAppIds, ownedGameIds)).length} possédé(s)`
          : ""}
      </p>

      {visibleGames.length > 0 ? (
        <div className="grid gap-5 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
          {visibleGames.map((game) => (
            <GameCard game={game} key={game.id} owned={isOwned(game, matchedAppIds, ownedGameIds)} />
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
