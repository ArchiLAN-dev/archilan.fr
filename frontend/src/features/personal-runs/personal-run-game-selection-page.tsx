"use client";

import Link from "next/link";
import { use, useCallback, useEffect, useRef, useState } from "react";
import { AlertCircle, ArrowLeft, CheckCircle, ChevronLeft, ChevronRight, ExternalLink, FileText, Search, X } from "lucide-react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { InstallNudge } from "@/features/games/install-nudge";
import { SteamCoupling } from "@/features/games/steam-coupling";
import { useSteamCoupling } from "@/features/games/use-steam-coupling";
import { FilterTokenBar, type ActiveFilterToken, type FilterGroup } from "@/features/games/filter-token-bar";
import { allCategories, categoriesOf, isOwned } from "@/features/games/games-filter";

// ─── Types ────────────────────────────────────────────────────────────────────

type AvailableGame = {
  id: string;
  name: string;
  slug: string;
  description: string;
  availability: string;
  isApworldReady: boolean;
  defaultYaml: string | null;
  coverImageUrl: string | null;
  coverImageAlt: string;
  platforms: string[];
  steamAppId: number | null;
};

type Slot = {
  slotId: string;
  gameId: string;
  gameName: string;
  playerYaml: string | null;
};

type RecentlyPlayedGame = {
  gameId: string;
  lastPlayedAt: string;
  runTitle: string;
};

type SelectionData = {
  slots: Slot[];
  availableGames: AvailableGame[];
  recentlyPlayedGames: RecentlyPlayedGame[];
};

type PageState =
  | { kind: "loading" }
  | { kind: "data"; data: SelectionData }
  | { kind: "not_found" }
  | { kind: "error"; message: string };

type SaveState =
  | { kind: "idle" }
  | { kind: "saving" }
  | { kind: "saved" }
  | { kind: "error"; message: string };

const PAGE_SIZE = 20;

const availabilityConfig: Record<string, { label: string; className: string }> = {
  available: { label: "Disponible", className: "border-success/50 bg-success/10 text-success" },
  experimental: { label: "Expérimental", className: "border-warning/50 bg-warning/10 text-warning" },
};

/** Short FR relative-time label ("à l'instant", "il y a 3 j"). Returns "" on an unparsable date. */
function relativeTime(iso: string): string {
  const ts = new Date(iso).getTime();
  if (Number.isNaN(ts)) return "";
  const diff = Date.now() - ts;
  if (diff < 60_000) return "à l'instant";
  if (diff < 3_600_000) return `il y a ${Math.floor(diff / 60_000)} min`;
  if (diff < 86_400_000) return `il y a ${Math.floor(diff / 3_600_000)} h`;
  return `il y a ${Math.floor(diff / 86_400_000)} j`;
}

// ─── Main page ────────────────────────────────────────────────────────────────

export function PersonalRunGameSelectionPage({
  params,
}: {
  params: Promise<{ runId: string }>;
}) {
  const { runId } = use(params);
  const [loadKey, setLoadKey] = useState(0);
  const [pageState, setPageState] = useState<PageState>({ kind: "loading" });
  const [workingGameIds, setWorkingGameIds] = useState<string[]>([]);
  const [gameSearch, setGameSearch] = useState("");
  const [currentPage, setCurrentPage] = useState(1);
  const [saveState, setSaveState] = useState<SaveState>({ kind: "idle" });
  const [justAdded, setJustAdded] = useState<Set<string>>(new Set());
  const [fadingOut, setFadingOut] = useState<Set<string>>(new Set());
  const addTimers = useRef<Map<string, [ReturnType<typeof setTimeout>, ReturnType<typeof setTimeout>]>>(new Map());

  const { matchedAppIds, coupled, couplingProps } = useSteamCoupling();
  const [selectedCategories, setSelectedCategories] = useState<string[]>([]);
  const [ownedOnly, setOwnedOnly] = useState(false);
  const [recentOnly, setRecentOnly] = useState(false);

  useEffect(() => {
    const timers = addTimers.current;
    return () => { timers.forEach(([t1, t2]) => { clearTimeout(t1); clearTimeout(t2); }); };
  }, []);

  useEffect(() => {
    if (!coupled && ownedOnly) {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setOwnedOnly(false);
    }
  }, [coupled, ownedOnly]);

  const handleAddGame = useCallback((gameId: string) => {
    setWorkingGameIds((prev) => [...prev, gameId]);
    setSaveState({ kind: "idle" });

    const existing = addTimers.current.get(gameId);
    if (existing) { clearTimeout(existing[0]); clearTimeout(existing[1]); }

    setFadingOut((prev) => { const next = new Set(prev); next.delete(gameId); return next; });
    setJustAdded((prev) => new Set(prev).add(gameId));

    const t1 = setTimeout(() => {
      setFadingOut((prev) => new Set(prev).add(gameId));
    }, 1100);
    const t2 = setTimeout(() => {
      setJustAdded((prev) => { const next = new Set(prev); next.delete(gameId); return next; });
      setFadingOut((prev) => { const next = new Set(prev); next.delete(gameId); return next; });
      addTimers.current.delete(gameId);
    }, 1400);

    addTimers.current.set(gameId, [t1, t2]);
  }, []);

  useEffect(() => {
    let cancelled = false;

    async function run() {
      const res = await apiFetch(`${env.apiBaseUrl}/runs/${runId}/participants/me/game-selection`);

      if (cancelled) return;

      if (res.status === 401 || res.status === 403) {
        window.location.href = `/connexion?returnTo=/runs/${runId}/jeux`;
        return;
      }

      if (res.status === 404) {
        setPageState({ kind: "not_found" });
        return;
      }

      if (!res.ok) {
        setPageState({ kind: "error", message: "Impossible de charger la sélection de jeux." });
        return;
      }

      const payload = (await res.json()) as {
        data: { slots: Slot[]; availableGames: AvailableGame[]; recentlyPlayedGames?: RecentlyPlayedGame[] };
      };
      const data: SelectionData = {
        slots: payload.data.slots,
        availableGames: payload.data.availableGames,
        recentlyPlayedGames: payload.data.recentlyPlayedGames ?? [],
      };

      setPageState({ kind: "data", data });
      setWorkingGameIds(data.slots.map((s) => s.gameId));
      setSaveState({ kind: "saved" });
    }

    void run().catch(() => {
      if (!cancelled) setPageState({ kind: "error", message: "Impossible de contacter l'API." });
    });

    return () => { cancelled = true; };
  }, [runId, loadKey]);

  async function handleSave() {
    if (pageState.kind !== "data") return;
    setSaveState({ kind: "saving" });
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/runs/${runId}/participants/me/games`, {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ gameIds: workingGameIds }),
      });
      if (!res.ok) {
        const body = (await res.json()) as { error?: { message?: string } };
        setSaveState({ kind: "error", message: body.error?.message ?? "Impossible de sauvegarder la sélection." });
        return;
      }
      setSaveState({ kind: "saved" });
      setLoadKey((k) => k + 1);
    } catch {
      setSaveState({ kind: "error", message: "Impossible de contacter l'API." });
    }
  }

  if (pageState.kind === "loading") {
    return (
      <div aria-hidden="true" className="mx-auto max-w-2xl grid gap-6">
        <div className="h-8 w-48 animate-pulse rounded bg-surface" />
        <div className="h-24 animate-pulse rounded-lg border border-border bg-surface" />
        <div className="grid gap-3">
          <div className="h-10 animate-pulse rounded bg-surface" />
          {[0, 1, 2, 3].map((i) => (
            <div className="h-16 animate-pulse rounded-lg border border-border bg-surface" key={i} />
          ))}
        </div>
      </div>
    );
  }

  if (pageState.kind === "not_found") {
    return (
      <div className="mx-auto max-w-2xl grid gap-4 rounded-lg border border-border p-8 text-center">
        <AlertCircle aria-hidden className="mx-auto size-8 text-[color:var(--color-danger)]" />
        <p className="font-heading text-xl font-semibold text-foreground">Partie introuvable</p>
        <p className="text-sm text-muted-foreground">
          Cette partie n&apos;existe pas ou tu n&apos;y as pas accès.
        </p>
        <Link className="text-sm text-accent-text hover:text-accent-text-hover" href="/runs">
          Mes parties
        </Link>
      </div>
    );
  }

  if (pageState.kind === "error") {
    return (
      <div className="mx-auto max-w-2xl grid gap-4 rounded-lg border border-border p-8 text-center">
        <AlertCircle aria-hidden className="mx-auto size-8 text-[color:var(--color-danger)]" />
        <p className="font-heading text-xl font-semibold text-foreground">Erreur</p>
        <p className="text-sm text-muted-foreground">{pageState.message}</p>
      </div>
    );
  }

  const { data } = pageState;
  const gameMap = new Map(data.availableGames.map((g) => [g.id, g]));

  // Rebuild saved slot map keyed by gameId for YAML links (post-save)
  const savedSlotsByGameId = new Map<string, Slot[]>();
  for (const slot of data.slots) {
    const existing = savedSlotsByGameId.get(slot.gameId) ?? [];
    existing.push(slot);
    savedSlotsByGameId.set(slot.gameId, existing);
  }

  // Build labeled working items
  const occurrenceCounts: Record<string, number> = {};
  for (const id of workingGameIds) {
    occurrenceCounts[id] = (occurrenceCounts[id] ?? 0) + 1;
  }
  const occurrenceProgress: Record<string, number> = {};
  const selectionItems = workingGameIds.map((gameId, idx) => {
    occurrenceProgress[gameId] = (occurrenceProgress[gameId] ?? 0) + 1;
    const n = occurrenceProgress[gameId];
    const total = occurrenceCounts[gameId] ?? 1;
    const name = gameMap.get(gameId)?.name ?? gameId;
    // Try to find a saved slot for YAML link (before save, slots match saved state)
    const savedSlots = savedSlotsByGameId.get(gameId) ?? [];
    const slot = savedSlots[n - 1] ?? null;
    const hasYaml = slot !== null && slot.playerYaml !== null && slot.playerYaml !== "";
    return { gameId, idx, label: total > 1 ? `${name} (monde ${n})` : name, slot, hasYaml };
  });

  // Distinct selected games (name + slug) for the post-selection install nudge (story 31.4).
  // Only games with a public detail page (available/experimental) - others would 404.
  const selectedGamesForNudge = Array.from(new Set(workingGameIds)).flatMap((id) => {
    const g = gameMap.get(id);
    return g && (g.availability === "available" || g.availability === "experimental")
      ? [{ name: g.name, slug: g.slug }]
      : [];
  });

  // Recently played: map gameId → metadata + recency rank (from the run history payload).
  const recentById = new Map(data.recentlyPlayedGames.map((r) => [r.gameId, r]));
  const recentRank = new Map(data.recentlyPlayedGames.map((r, i) => [r.gameId, i]));
  const hasRecent = data.recentlyPlayedGames.length > 0;

  // Filtered + paginated catalog
  const q = gameSearch.trim().toLowerCase();
  const categoryOptions = allCategories(data.availableGames);
  const selectedCategorySet = new Set(selectedCategories);
  const filteredGames = data.availableGames.filter((g) => {
    if (q !== "" && !(g.name.toLowerCase().includes(q) || g.description.toLowerCase().includes(q))) {
      return false;
    }
    if (ownedOnly && !isOwned(g, matchedAppIds)) return false;
    if (recentOnly && !recentById.has(g.id)) return false;
    if (selectedCategorySet.size > 0 && !categoriesOf(g).some((c) => selectedCategorySet.has(c))) {
      return false;
    }
    return true;
  });

  // Default view: bubble recently-played games to the top (recency order), excluding ones already
  // selected (they live under "Ma sélection"). A live search reverts to the flat, name-ordered list.
  let displayGames = filteredGames;
  if (q === "" && hasRecent) {
    const workingSet = new Set(workingGameIds);
    const pinned = filteredGames
      .filter((g) => recentById.has(g.id) && !workingSet.has(g.id))
      .sort((a, b) => (recentRank.get(a.id) ?? 0) - (recentRank.get(b.id) ?? 0));
    if (pinned.length > 0) {
      const pinnedIds = new Set(pinned.map((g) => g.id));
      displayGames = [...pinned, ...filteredGames.filter((g) => !pinnedIds.has(g.id))];
    }
  }

  const totalPages = Math.max(1, Math.ceil(displayGames.length / PAGE_SIZE));
  const safePage = Math.min(currentPage, totalPages);
  const pageGames = displayGames.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE);

  const hasActiveFilters = q !== "" || recentOnly || ownedOnly || selectedCategories.length > 0;
  const clearFilters = () => {
    setGameSearch("");
    setRecentOnly(false);
    setOwnedOnly(false);
    setSelectedCategories([]);
    setCurrentPage(1);
  };

  // Filters as cumulable tokens via the shared FilterTokenBar. (Search stays a separate field above.)
  const addFilter = (value: string) => {
    if ("__recent" === value) setRecentOnly(true);
    else if ("__owned" === value) setOwnedOnly(true);
    else if (value.startsWith("cat:")) {
      const category = value.slice(4);
      setSelectedCategories((prev) => (prev.includes(category) ? prev : [...prev, category]));
    }
    setCurrentPage(1);
  };

  const filterGroups: FilterGroup[] = [
    {
      label: "Filtres",
      options: [
        ...(hasRecent && !recentOnly ? [{ value: "__recent", label: "Récemment joués" }] : []),
        ...(coupled && !ownedOnly ? [{ value: "__owned", label: "Mes jeux" }] : []),
      ],
    },
    {
      label: "Plateformes",
      options: categoryOptions
        .filter((c) => !selectedCategories.includes(c))
        .map((c) => ({ value: `cat:${c}`, label: c })),
    },
  ];

  const activeTokens: ActiveFilterToken[] = [];
  if (recentOnly) {
    activeTokens.push({ key: "__recent", label: "Récemment joués", icon: "clock", remove: () => { setRecentOnly(false); setCurrentPage(1); } });
  }
  if (ownedOnly) {
    activeTokens.push({ key: "__owned", label: "Mes jeux", icon: "gamepad", remove: () => { setOwnedOnly(false); setCurrentPage(1); } });
  }
  for (const category of selectedCategories) {
    activeTokens.push({
      key: `cat:${category}`,
      label: category,
      remove: () => { setSelectedCategories((prev) => prev.filter((c) => c !== category)); setCurrentPage(1); },
    });
  }

  return (
    <article className="mx-auto max-w-2xl grid gap-8">
      <header className="grid gap-2">
        <Link
          className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground w-fit"
          href={`/runs/${runId}`}
        >
          <ArrowLeft aria-hidden className="size-3.5" />
          Retour à la partie
        </Link>
        <h1 className="font-heading text-3xl font-bold leading-tight text-foreground">
          Mes jeux
        </h1>
        <p className="text-sm text-muted-foreground">
          Choisis les jeux que tu veux inclure dans la partie. Tu pourras configurer le YAML de chaque slot après avoir sauvegardé.
        </p>
      </header>

      {/* ── Selection summary ── */}
      <section className="card-glow grid gap-4 rounded-lg border border-border p-5">
        <div className="flex items-center justify-between gap-2">
          <h2 className="font-heading text-lg font-semibold text-foreground">
            Ma sélection
            {workingGameIds.length > 0 && (
              <span className="ml-2 text-sm font-normal text-muted-foreground">
                ({workingGameIds.length})
              </span>
            )}
          </h2>
          {saveState.kind === "saved" && workingGameIds.length > 0 && (
            <span className="inline-flex items-center gap-1.5 rounded-full border border-[color:var(--color-success)]/20 bg-[color:var(--color-success)]/10 px-2.5 py-1 text-xs font-medium text-[color:var(--color-success)]">
              <CheckCircle aria-hidden className="size-3" />
              Sauvegardé
            </span>
          )}
        </div>

        {selectionItems.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            Aucun jeu sélectionné. Parcours le catalogue ci-dessous pour ajouter des jeux.
          </p>
        ) : (
          <ul className="grid gap-1.5" role="list">
            {selectionItems.map(({ idx, label, slot, hasYaml }) => (
              <li
                className="flex items-center justify-between gap-3 rounded border border-border bg-background px-3 py-2"
                key={idx}
              >
                <span className="text-sm font-medium text-foreground">{label}</span>
                <div className="flex items-center gap-1.5">
                  {slot !== null && saveState.kind === "saved" && (
                    <Link
                      className={[
                        "inline-flex items-center gap-1.5 rounded border px-2 py-1 text-xs font-semibold transition-colors",
                        hasYaml
                          ? "border-[color:var(--color-success)]/30 bg-[color:var(--color-success)]/10 text-[color:var(--color-success)] hover:bg-[color:var(--color-success)]/20"
                          : "border-border text-muted-foreground hover:text-foreground",
                      ].join(" ")}
                      href={`/runs/${runId}/slots/${slot.slotId}`}
                    >
                      <FileText aria-hidden className="size-3" />
                      {hasYaml ? "YAML configuré" : "Config YAML"}
                    </Link>
                  )}
                  <button
                    aria-label={`Retirer ${label}`}
                    className="inline-flex size-7 items-center justify-center rounded text-muted-foreground transition-colors hover:bg-[color:var(--color-danger)]/10 hover:text-[color:var(--color-danger)]"
                    onClick={() => {
                      setWorkingGameIds((prev) => prev.filter((_, i) => i !== idx));
                      setSaveState({ kind: "idle" });
                    }}
                    type="button"
                  >
                    <X aria-hidden className="size-3.5" />
                  </button>
                </div>
              </li>
            ))}
          </ul>
        )}

        <div className="grid gap-2">
          <button
            className="inline-flex min-h-10 w-full cursor-pointer items-center justify-center rounded bg-accent px-5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-50 sm:w-fit"
            disabled={saveState.kind === "saving"}
            onClick={() => { void handleSave(); }}
            type="button"
          >
            {saveState.kind === "saving" ? "Sauvegarde…" : "Sauvegarder ma sélection"}
          </button>
          {saveState.kind === "error" && (
            <p className="text-xs text-[color:var(--color-danger)]">{saveState.message}</p>
          )}
        </div>
      </section>

      <InstallNudge games={selectedGamesForNudge} />

      {/* ── Steam coupling ── */}
      <SteamCoupling {...couplingProps} />

      {/* ── Game catalog ── */}
      <section className="grid gap-4">
        <h2 className="font-heading text-xl font-semibold text-foreground">
          Catalogue
          <span className="ml-2 text-sm font-normal text-muted-foreground">
            ({data.availableGames.length})
          </span>
        </h2>

        <div className="relative">
          <Search
            aria-hidden
            className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
          />
          <input
            className="min-h-10 w-full rounded border border-border bg-background pl-9 pr-3 text-sm text-foreground outline-none focus:border-accent"
            placeholder={`Rechercher parmi ${data.availableGames.length} jeux…`}
            type="search"
            value={gameSearch}
            onChange={(e) => {
              setGameSearch(e.target.value);
              setCurrentPage(1);
            }}
          />
        </div>

        <FilterTokenBar
          activeTokens={activeTokens}
          groups={filterGroups}
          hasActiveFilters={hasActiveFilters}
          onAdd={addFilter}
          onClear={clearFilters}
        />

        {filteredGames.length === 0 ? (
          <p className="text-sm text-muted-foreground">Aucun jeu ne correspond à la recherche.</p>
        ) : (
          <>
            <ul className="divide-y divide-border rounded-lg border border-border" role="list">
              {pageGames.map((game) => {
                const added = justAdded.has(game.id);
                const fading = fadingOut.has(game.id);
                const recent = recentById.get(game.id) ?? null;
                const recentRel = recent ? relativeTime(recent.lastPlayedAt) : "";
                return (
                  <li
                    className="flex items-center gap-3 bg-background px-3 py-3 first:rounded-t-lg last:rounded-b-lg transition-colors hover:bg-surface"
                    key={game.id}
                  >
                    <div className="h-16 w-12 shrink-0 overflow-hidden rounded border border-border bg-surface">
                      {game.coverImageUrl ? (
                        // eslint-disable-next-line @next/next/no-img-element
                        <img
                          alt={game.coverImageAlt ?? game.name}
                          className="h-full w-full object-cover object-top"
                          src={game.coverImageUrl}
                        />
                      ) : (
                        <div className="flex h-full w-full items-center justify-center text-xs font-semibold text-muted-foreground">
                          {game.name.slice(0, 2).toUpperCase()}
                        </div>
                      )}
                    </div>

                    <div className="min-w-0 flex-1">
                      <Link
                        className="inline-flex items-center gap-1 text-sm font-semibold leading-tight text-foreground transition-colors hover:text-accent-text"
                        href={`/jeux/${game.slug}`}
                        rel="noopener noreferrer"
                        target="_blank"
                        title={`Voir la page de ${game.name}`}
                      >
                        {game.name}
                        <ExternalLink aria-hidden className="size-3 shrink-0 text-muted-foreground" />
                      </Link>
                      <div className="mt-1 flex flex-wrap gap-1.5">
                        {availabilityConfig[game.availability] && (
                          <span
                            className={`rounded border px-1.5 py-0.5 text-[11px] font-semibold ${availabilityConfig[game.availability].className}`}
                          >
                            {availabilityConfig[game.availability].label}
                          </span>
                        )}
                        {isOwned(game, matchedAppIds) && (
                          <span className="rounded border border-success/50 bg-success/10 px-1.5 py-0.5 text-[11px] font-semibold text-success">
                            Tu possèdes ce jeu
                          </span>
                        )}
                        {recent && (
                          <span
                            className="rounded border border-accent/40 bg-accent/10 px-1.5 py-0.5 text-[11px] font-semibold text-accent-text"
                            title={`Joué dans « ${recent.runTitle} »`}
                          >
                            {recentRel !== "" ? `Joué ${recentRel}` : "Récemment joué"}
                          </span>
                        )}
                      </div>
                      {game.description && (
                        <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{game.description}</p>
                      )}
                    </div>

                    <button
                      className={[
                        "shrink-0 inline-flex min-h-9 cursor-pointer items-center justify-center gap-1.5 rounded border px-3 text-xs font-semibold transition-all duration-300",
                        added
                          ? "border-[color:var(--color-success)]/30 bg-[color:var(--color-success)]/10 text-[color:var(--color-success)]"
                          : "border-border text-foreground hover:border-accent hover:text-accent-text",
                        fading ? "opacity-0" : "opacity-100",
                      ].join(" ")}
                      onClick={() => handleAddGame(game.id)}
                      type="button"
                    >
                      {added ? (
                        <>
                          <CheckCircle aria-hidden className="size-3" />
                          Ajouté
                        </>
                      ) : (
                        "+ Ajouter"
                      )}
                    </button>
                  </li>
                );
              })}
            </ul>

            {totalPages > 1 && (
              <div className="flex items-center justify-between gap-2">
                <button
                  className="inline-flex min-h-9 cursor-pointer items-center justify-center gap-1 rounded border border-border px-3 text-xs font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-40"
                  disabled={safePage === 1}
                  onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
                  type="button"
                >
                  <ChevronLeft aria-hidden className="size-3.5" />
                  Précédent
                </button>
                <span className="text-sm text-muted-foreground">
                  Page {safePage} / {totalPages}
                </span>
                <button
                  className="inline-flex min-h-9 cursor-pointer items-center justify-center gap-1 rounded border border-border px-3 text-xs font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-40"
                  disabled={safePage === totalPages}
                  onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
                  type="button"
                >
                  Suivant
                  <ChevronRight aria-hidden className="size-3.5" />
                </button>
              </div>
            )}
          </>
        )}
      </section>
    </article>
  );
}
