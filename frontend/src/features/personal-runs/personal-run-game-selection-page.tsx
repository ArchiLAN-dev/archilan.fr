"use client";

import Link from "next/link";
import { use, useCallback, useEffect, useRef, useState } from "react";
import { AlertCircle, ArrowLeft, CheckCircle, ChevronDown, ChevronLeft, ChevronRight, ChevronUp, ExternalLink, FileText, Search, X } from "lucide-react";
import { useQuery, useQueryClient } from "@tanstack/react-query";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { InstallNudge } from "@/features/games/install-nudge";
import { SteamCoupling } from "@/features/games/steam-coupling";
import { useSteamCoupling } from "@/features/games/use-steam-coupling";
import { FilterTokenBar, type ActiveFilterToken, type FilterGroup } from "@/features/games/filter-token-bar";
import { useGameList } from "@/features/games/use-game-list";
import { allCategories, isOwned, type ListFilter } from "@/features/games/games-filter";
import {
  filterPickerGames,
  orderPickerGames,
  pickerFilterOptions,
  OWNED_FILTER,
  PLANNED_FILTER,
  RECENT_FILTER,
} from "@/features/games/game-picker-filters";
import { fetchMyGameSelection, requestSlotPreflight, type GameSelectionSlot } from "./personal-runs-api";

// ─── Types ────────────────────────────────────────────────────────────────────

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
  const queryClient = useQueryClient();
  const [workingGameIds, setWorkingGameIds] = useState<string[]>([]);
  const [gameSearch, setGameSearch] = useState("");
  const [currentPage, setCurrentPage] = useState(1);
  const [saveState, setSaveState] = useState<SaveState>({ kind: "idle" });
  const [justAdded, setJustAdded] = useState<Set<string>>(new Set());
  const [fadingOut, setFadingOut] = useState<Set<string>>(new Set());
  const [expandedPreflightSlotId, setExpandedPreflightSlotId] = useState<string | null>(null);
  const addTimers = useRef<Map<string, [ReturnType<typeof setTimeout>, ReturnType<typeof setTimeout>]>>(new Map());

  const [selectedCategories, setSelectedCategories] = useState<string[]>([]);
  const [list, setList] = useState<ListFilter>("all");

  // Story 28.11: same rule as the public catalog - an explicit coupling filters to owned games.
  const { matchedAppIds, coupled, couplingProps } = useSteamCoupling({
    onExplicitCouple: () => setList("owned"),
  });
  // Stories 28.13/28.15: the player's own lists, read here too. This page used to know only the
  // Steam coupling, so a GameCube title marked by hand showed up in /jeux and not here - the same
  // question answered twice. The hooks sit with the others, above this component's early returns.
  const { gameIds: ownedGameIds, settled: ownedSettled } = useGameList("owned");
  // Story 28.15: and the "à essayer" list, at the one moment it is worth something - choosing what
  // to launch. Read only: a run is composed here, an inventory is not kept here.
  const { gameIds: plannedGameIds, settled: plannedSettled } = useGameList("planned");
  const hasAnyOwnership = coupled || ownedGameIds.size > 0;
  const hasPlanned = plannedGameIds.size > 0;
  const [recentOnly, setRecentOnly] = useState(false);

  useEffect(() => {
    const timers = addTimers.current;
    return () => { timers.forEach(([t1, t2]) => { clearTimeout(t1); clearTimeout(t2); }); };
  }, []);

  // Drop a list filter once its list proves empty - a dropped Steam coupling with nothing marked by
  // hand, or an "à essayer" list emptied elsewhere. Each waits for its own source to settle: an
  // empty list means "nothing on it" only once the session has resolved and the query has answered.
  useEffect(() => {
    const strandedOnOwned = ownedSettled && !hasAnyOwnership && "owned" === list;
    const strandedOnPlanned = plannedSettled && !hasPlanned && "planned" === list;
    if (strandedOnOwned || strandedOnPlanned) {
      // eslint-disable-next-line react-hooks/set-state-in-effect -- reset the filter when its external source (Steam coupling, player list) resolves to empty; guarded so it fires once per transition
      setList("all");
    }
  }, [ownedSettled, hasAnyOwnership, plannedSettled, hasPlanned, list]);

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

  // fetchMyGameSelection never throws (401/403, 404, server errors and network failures are all
  // encoded in the result's `kind`), so the query never errors and - like the old effect - never
  // retries.
  const selectionQuery = useQuery({
    queryKey: ["personal-run-game-selection", runId],
    queryFn: () => fetchMyGameSelection(runId),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    // Story 9.42: while a slot's solo test generation is pending, poll for its verdict.
    refetchInterval: (query) => {
      const d = query.state.data;
      return d?.kind === "data" && d.data.slots.some((s) => s.preflight?.status === "pending") ? 10_000 : false;
    },
  });
  const result = selectionQuery.data;
  const selection = result?.kind === "data" ? result.data : null;
  const resultKind = result?.kind;

  // 401/403: full-page login redirect, exactly as the old effect did.
  useEffect(() => {
    if (resultKind === "unauthorized") {
      window.location.href = `/connexion?returnTo=/runs/${runId}/jeux`;
    }
  }, [resultKind, runId]);

  // Seed-into-form hydration: the working selection re-seeds from the saved slots whenever the
  // server data changes (initial load + post-save refetch, formerly the loadKey bump).
  useEffect(() => {
    if (selection === null) return;
    // eslint-disable-next-line react-hooks/set-state-in-effect -- sanctioned seed-into-form hydration keyed on data identity; the editable selection is local UI state (AC-ST2), not query state
    setWorkingGameIds(selection.slots.map((s) => s.gameId));
    setSaveState({ kind: "saved" });
  }, [selection]);

  async function handleSave() {
    if (result?.kind !== "data") return;
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
      void queryClient.invalidateQueries({ queryKey: ["personal-run-game-selection", runId] });
    } catch {
      setSaveState({ kind: "error", message: "Impossible de contacter l'API." });
    }
  }

  if (selectionQuery.isPending || resultKind === "unauthorized") {
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

  if (resultKind === "not_found") {
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

  if (selection === null) {
    const message =
      result?.kind === "error" && result.reason === "network"
        ? "Impossible de contacter l'API."
        : "Impossible de charger la sélection de jeux.";
    return (
      <div className="mx-auto max-w-2xl grid gap-4 rounded-lg border border-border p-8 text-center">
        <AlertCircle aria-hidden className="mx-auto size-8 text-[color:var(--color-danger)]" />
        <p className="font-heading text-xl font-semibold text-foreground">Erreur</p>
        <p className="text-sm text-muted-foreground">{message}</p>
      </div>
    );
  }

  const data = selection;
  // Once the run leaves draft the multiworld is generated/fixed (paused/idle included): editing the
  // selection is a no-op since resume replays the existing session, so the UI is locked.
  const locked = data.status !== "draft";
  const gameMap = new Map(data.availableGames.map((g) => [g.id, g]));

  // Rebuild saved slot map keyed by gameId for YAML links (post-save)
  const savedSlotsByGameId = new Map<string, GameSelectionSlot[]>();
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
    return { gameId, n, idx, label: total > 1 ? `${name} (monde ${n})` : name, slot, hasYaml };
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

  // Filtered + paginated catalog. The derivation itself lives in games/game-picker-filters as pure
  // code, so what this page decides to show can be tested without mounting it (story 28.15), and
  // the event registration picker derives its own list the same way (story 28.16).
  const categoryOptions = allCategories(data.availableGames);
  const runFilters = { query: gameSearch, list, recentOnly, categories: selectedCategories };
  const playerSources = {
    ownedGameIds,
    plannedGameIds,
    steamAppIds: matchedAppIds,
  };
  const filteredGames = filterPickerGames(
    data.availableGames,
    runFilters,
    playerSources,
    new Set(recentById.keys()),
  );
  const displayGames = orderPickerGames(filteredGames, recentRank, new Set(workingGameIds), gameSearch);

  const totalPages = Math.max(1, Math.ceil(displayGames.length / PAGE_SIZE));
  const safePage = Math.min(currentPage, totalPages);
  const pageGames = displayGames.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE);

  const hasActiveFilters =
    gameSearch.trim() !== "" || recentOnly || "all" !== list || selectedCategories.length > 0;
  const clearFilters = () => {
    setGameSearch("");
    setRecentOnly(false);
    setList("all");
    setSelectedCategories([]);
    setCurrentPage(1);
  };

  // Filters as cumulable tokens via the shared FilterTokenBar, except that the two list tokens
  // replace each other: "mes jeux" and "à essayer" are two answers to one question. "Récemment
  // joués" is a different axis and stays cumulable with either. (Search stays a field above.)
  const addFilter = (value: string) => {
    if (RECENT_FILTER === value) setRecentOnly(true);
    else if (OWNED_FILTER === value) setList("owned");
    else if (PLANNED_FILTER === value) setList("planned");
    else if (value.startsWith("cat:")) {
      const category = value.slice(4);
      setSelectedCategories((prev) => (prev.includes(category) ? prev : [...prev, category]));
    }
    setCurrentPage(1);
  };

  const filterGroups: FilterGroup[] = [
    {
      label: "Filtres",
      options: pickerFilterOptions(
        { hasOwnership: hasAnyOwnership, hasPlanned, hasRecent },
        runFilters,
      ),
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
    activeTokens.push({ key: RECENT_FILTER, label: "Récemment joués", icon: "clock", remove: () => { setRecentOnly(false); setCurrentPage(1); } });
  }
  if ("all" !== list) {
    activeTokens.push({
      key: `list:${list}`,
      label: "owned" === list ? "Mes jeux" : "À essayer",
      icon: "owned" === list ? "gamepad" : "bookmark",
      remove: () => { setList("all"); setCurrentPage(1); },
    });
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

      {locked && (
        <div className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm text-foreground">
          <AlertCircle aria-hidden className="mt-0.5 size-4 shrink-0 text-warning" />
          <p>
            La partie a déjà été générée : la sélection de jeux et les YAML ne sont plus modifiables.
            La reprise rejoue toujours la partie existante.
          </p>
        </div>
      )}

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
            {selectionItems.map(({ gameId, n, idx, label, slot, hasYaml }) => (
              <li
                className="rounded border border-border bg-background px-3 py-2"
                key={`${gameId}-${n}`}
              >
                <div className="flex items-center justify-between gap-3">
                <span className="text-sm font-medium text-foreground">{label}</span>
                <div className="flex items-center gap-1.5">
                  {slot !== null && saveState.kind === "saved" && hasYaml && (
                    <SlotPreflightBadge
                      detailsOpen={expandedPreflightSlotId === slot.slotId}
                      onRetest={async () => {
                        const accepted = await requestSlotPreflight(runId, slot.slotId);
                        if (accepted) {
                          void queryClient.invalidateQueries({ queryKey: ["personal-run-game-selection", runId] });
                        }
                      }}
                      onToggleDetails={() =>
                        setExpandedPreflightSlotId((prev) => (prev === slot.slotId ? null : slot.slotId))
                      }
                      preflight={slot.preflight ?? null}
                    />
                  )}
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
                    className="inline-flex size-7 items-center justify-center rounded text-muted-foreground transition-colors hover:bg-[color:var(--color-danger)]/10 hover:text-[color:var(--color-danger)] disabled:cursor-not-allowed disabled:opacity-40"
                    disabled={locked}
                    onClick={() => {
                      setWorkingGameIds((prev) => prev.filter((_, i) => i !== idx));
                      setSaveState({ kind: "idle" });
                    }}
                    type="button"
                  >
                    <X aria-hidden className="size-3.5" />
                  </button>
                </div>
                </div>
                {slot !== null &&
                  slot.preflight?.status === "failed" &&
                  expandedPreflightSlotId === slot.slotId && (
                    <div className="mt-2 rounded border border-[color:var(--color-danger)]/30 bg-[color:var(--color-danger)]/5 p-2">
                      <pre className="max-h-40 overflow-auto whitespace-pre-wrap break-all text-[11px] leading-relaxed text-muted-foreground">
                        {slot.preflight.error !== ""
                          ? slot.preflight.error
                          : "Échec du test de génération (aucun détail disponible)."}
                      </pre>
                    </div>
                  )}
              </li>
            ))}
          </ul>
        )}

        <div className="grid gap-2">
          <button
            className="inline-flex min-h-10 w-full cursor-pointer items-center justify-center rounded bg-accent px-5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-50 sm:w-fit"
            disabled={saveState.kind === "saving" || locked}
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
                    className={`flex items-center gap-3 bg-background px-3 py-3 first:rounded-t-lg last:rounded-b-lg transition-colors hover:bg-surface ${game.disabled ? "opacity-60" : ""}`}
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
                        {game.disabled && (
                          <span
                            className="rounded border border-danger/50 bg-danger/10 px-1.5 py-0.5 text-[11px] font-semibold text-danger"
                            title={game.disabledMessage ?? undefined}
                          >
                            Désactivé
                          </span>
                        )}
                        {availabilityConfig[game.availability] && (
                          <span
                            className={`rounded border px-1.5 py-0.5 text-[11px] font-semibold ${availabilityConfig[game.availability].className}`}
                          >
                            {availabilityConfig[game.availability].label}
                          </span>
                        )}
                        {isOwned(game, matchedAppIds, ownedGameIds) && (
                          <span className="rounded border border-success/50 bg-success/10 px-1.5 py-0.5 text-[11px] font-semibold text-success">
                            Tu possèdes ce jeu
                          </span>
                        )}
                        {plannedGameIds.has(game.id) && (
                          <span className="rounded border border-accent/50 bg-accent/10 px-1.5 py-0.5 text-[11px] font-semibold text-accent-text">
                            À essayer
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
                      {game.disabled ? (
                        <p className="mt-0.5 text-xs text-danger">
                          Temporairement désactivé{game.disabledMessage ? ` : ${game.disabledMessage}` : "."}
                        </p>
                      ) : game.description ? (
                        <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{game.description}</p>
                      ) : null}
                    </div>

                    <button
                      className={[
                        "shrink-0 inline-flex min-h-9 cursor-pointer items-center justify-center gap-1.5 rounded border px-3 text-xs font-semibold transition-all duration-300 disabled:cursor-not-allowed disabled:opacity-40",
                        added
                          ? "border-[color:var(--color-success)]/30 bg-[color:var(--color-success)]/10 text-[color:var(--color-success)]"
                          : "border-border text-foreground hover:border-accent hover:text-accent-text",
                        fading ? "opacity-0" : "opacity-100",
                      ].join(" ")}
                      disabled={locked || game.disabled}
                      title={game.disabled ? (game.disabledMessage ?? "Jeu temporairement désactivé") : undefined}
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

// ─── Slot preflight badge (story 9.42) ───────────────────────────────────────

/**
 * Advisory verdict of the slot's solo test generation. "Tester" queues a re-run; the page's
 * query polls while a test is pending. A failed badge is a toggle that expands the error
 * detail under the slot row (a native tooltip alone was not discoverable enough).
 */
function SlotPreflightBadge({
  preflight,
  detailsOpen,
  onToggleDetails,
  onRetest,
}: {
  preflight: { status: "pending" | "passed" | "failed"; error: string; checkedAt: string } | null;
  detailsOpen: boolean;
  onToggleDetails: () => void;
  onRetest: () => Promise<void>;
}) {
  const [busy, setBusy] = useState(false);

  if (preflight?.status === "pending") {
    return (
      <span className="inline-flex items-center gap-1 rounded border border-border px-2 py-1 text-xs text-muted-foreground">
        Test en cours…
      </span>
    );
  }

  const badge =
    preflight === null ? null : preflight.status === "passed" ? (
      <span
        className="inline-flex items-center gap-1 rounded border border-[color:var(--color-success)]/30 bg-[color:var(--color-success)]/10 px-2 py-1 text-xs font-semibold text-[color:var(--color-success)]"
        title="Testé seul avec une seed - la génération complète peut encore différer."
      >
        <CheckCircle aria-hidden className="size-3" />
        Config testée
      </span>
    ) : (
      <button
        aria-expanded={detailsOpen}
        className="inline-flex cursor-pointer items-center gap-1 rounded border border-[color:var(--color-danger)]/30 bg-[color:var(--color-danger)]/10 px-2 py-1 text-xs font-semibold text-[color:var(--color-danger)] transition-colors hover:bg-[color:var(--color-danger)]/20"
        onClick={onToggleDetails}
        type="button"
      >
        <AlertCircle aria-hidden className="size-3" />
        Échec du test
        {detailsOpen ? (
          <ChevronUp aria-hidden className="size-3" />
        ) : (
          <ChevronDown aria-hidden className="size-3" />
        )}
      </button>
    );

  return (
    <>
      {badge}
      <button
        className="inline-flex items-center rounded border border-border px-2 py-1 text-xs font-semibold text-muted-foreground transition-colors hover:border-accent hover:text-foreground disabled:cursor-not-allowed disabled:opacity-50"
        disabled={busy}
        onClick={() => {
          setBusy(true);
          void onRetest().finally(() => setBusy(false));
        }}
        title="Teste cette config seule, avec une seed unique : un échec signale un YAML à corriger ; une réussite ne garantit pas la génération complète de la partie."
        type="button"
      >
        Tester ma config
      </button>
    </>
  );
}
