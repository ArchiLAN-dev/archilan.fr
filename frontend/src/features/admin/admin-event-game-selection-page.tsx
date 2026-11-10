"use client";

import {
  AlertTriangle,
  Check,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  Gamepad2,
  ListChecks,
  ListX,
  Minus,
  ShieldAlert,
  Sparkles,
  Trash2,
  X,
} from "lucide-react";
import Link from "next/link";
import { useEffect, useMemo, useRef, useState } from "react";
import type { FormEvent } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";

import { ThumbnailPreview } from "@/features/admin/admin-game-library-dashboard";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { cn } from "@/lib/utils";
import { fetchAdminEventGameSelection, fetchAdminEventTitle, type AvailableGame } from "./admin-events-api";

type AvailabilityScope = "available" | "available_experimental";
type ApworldFilter = "all" | "ready" | "not_ready";
type SortDir = "asc" | "desc";
type MobilePane = "catalogue" | "selection";

type PageState =
  | { kind: "loading" }
  | { kind: "ready"; eventTitle: string }
  | { kind: "error"; message: string };

/** What the page saves; compared with the last saved one to know whether there is anything to save. */
type Draft = { enabled: boolean; maxGames: number | null; gameIds: ReadonlySet<string> };

type SaveState = { kind: "idle" } | { kind: "saving" } | { kind: "saved" } | { kind: "error"; message: string };

/** Story 3.16: the catalogue is paged - 800 rows at once buried the save button. */
export const CATALOGUE_PAGE_SIZE = 50;

export function AdminEventGameSelectionPage({ eventId }: { eventId: string }) {
  const queryClient = useQueryClient();
  const [enabled, setEnabled] = useState(false);
  const [maxGames, setMaxGames] = useState<number | null>(null);
  const [selectedGameIds, setSelectedGameIds] = useState<Set<string>>(new Set());
  const [saved, setSaved] = useState<Draft | null>(null);
  const [hydratedEventId, setHydratedEventId] = useState<string | null>(null);
  const [scope, setScope] = useState<AvailabilityScope>("available");
  const [search, setSearch] = useState("");
  const [apworldFilter, setApworldFilter] = useState<ApworldFilter>("all");
  const [platform, setPlatform] = useState<string>("");
  const [sortDir, setSortDir] = useState<SortDir>("asc");
  const [selectedOnly, setSelectedOnly] = useState(false);
  const [page, setPage] = useState(1);
  const [pane, setPane] = useState<MobilePane>("catalogue");
  const [save, setSave] = useState<SaveState>({ kind: "idle" });

  // Both query fns never throw (failures are encoded in the result / degrade to null), so the
  // queries never error and never retry. The two requests run in parallel.
  const configQuery = useQuery({
    queryKey: ["admin-event-game-selection", eventId],
    queryFn: () => fetchAdminEventGameSelection(eventId),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  const titleQuery = useQuery({
    queryKey: ["admin-event-title", eventId],
    queryFn: () => fetchAdminEventTitle(eventId),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  const configResult = configQuery.data;

  // One-shot seed of the editing state from the fetched config, keyed on the event identity so
  // background refetches never clobber in-progress edits.
  useEffect(() => {
    if (configResult?.kind !== "ready" || hydratedEventId === eventId) return;
    const seeded: Draft = {
      enabled: configResult.config.gameSelectionEnabled,
      maxGames: configResult.config.gameSelectionMax ?? null,
      gameIds: new Set(configResult.config.selectedGames.map((g) => g.gameId)),
    };
    /* eslint-disable react-hooks/set-state-in-effect -- sanctioned one-shot seed-into-form hydration (guarded by hydratedEventId); the editable selection is local UI state (AC-ST2), not query state */
    setEnabled(seeded.enabled);
    setMaxGames(seeded.maxGames);
    setSelectedGameIds(new Set(seeded.gameIds));
    setSaved(seeded);
    setHydratedEventId(eventId);
    /* eslint-enable react-hooks/set-state-in-effect */
  }, [configResult, hydratedEventId, eventId]);

  const dirty = saved !== null && isDraftDirty(saved, { enabled, maxGames, gameIds: selectedGameIds });

  // Guard against losing edits on a hard navigation / refresh.
  useEffect(() => {
    if (!dirty) return;
    const handler = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = "";
    };
    window.addEventListener("beforeunload", handler);
    return () => window.removeEventListener("beforeunload", handler);
  }, [dirty]);

  const state: PageState =
    configResult === undefined ||
    titleQuery.data === undefined ||
    (configResult.kind === "ready" && hydratedEventId !== eventId)
      ? { kind: "loading" }
      : configResult.kind === "error"
        ? { kind: "error", message: configResult.message }
        : { kind: "ready", eventTitle: titleQuery.data ?? "" };

  const allGames = useMemo(
    () => (configResult?.kind === "ready" ? configResult.config.availableGames : []),
    [configResult],
  );
  const gamesById = useMemo(() => new Map(allGames.map((g) => [g.id, g])), [allGames]);
  // A selected game may have left the catalogue since (no longer available): its saved name still shows.
  const savedNames = useMemo(
    () =>
      new Map(
        configResult?.kind === "ready" ? configResult.config.selectedGames.map((g) => [g.gameId, g.gameName]) : [],
      ),
    [configResult],
  );

  const platformOptions = useMemo(() => {
    const set = new Set<string>();
    for (const game of allGames) {
      for (const family of game.platforms) set.add(family);
    }
    return [...set].sort((a, b) => a.localeCompare(b));
  }, [allGames]);

  const filteredGames = useMemo(() => {
    let list = allGames.filter((g) =>
      scope === "available"
        ? g.availability === "available"
        : g.availability === "available" || g.availability === "experimental",
    );
    if (apworldFilter === "ready") list = list.filter((g) => g.isApworldReady);
    else if (apworldFilter === "not_ready") list = list.filter((g) => !g.isApworldReady);
    if (platform) list = list.filter((g) => g.platforms.includes(platform));
    if (search) {
      const q = search.toLowerCase();
      list = list.filter((g) => g.name.toLowerCase().includes(q) || g.slug.includes(q));
    }
    if (selectedOnly) list = list.filter((g) => selectedGameIds.has(g.id));
    return [...list].sort((a, b) => {
      const cmp = a.name.localeCompare(b.name);
      return sortDir === "asc" ? cmp : -cmp;
    });
  }, [allGames, scope, apworldFilter, platform, search, selectedOnly, selectedGameIds, sortDir]);

  const { pageItems, safePage, totalPages } = paginate(filteredGames, page, CATALOGUE_PAGE_SIZE);

  // Tri-state for the "select all filtered" header checkbox.
  const filteredSelectedCount = useMemo(
    () => filteredGames.reduce((n, g) => (selectedGameIds.has(g.id) ? n + 1 : n), 0),
    [filteredGames, selectedGameIds],
  );
  const allFilteredSelected = filteredGames.length > 0 && filteredSelectedCount === filteredGames.length;
  const pageAllSelected = pageItems.length > 0 && pageItems.every((g) => selectedGameIds.has(g.id));
  const pageSomeSelected = !pageAllSelected && pageItems.some((g) => selectedGameIds.has(g.id));

  const selectionList = useMemo(
    () =>
      [...selectedGameIds]
        .map((id) => ({ id, name: gamesById.get(id)?.name ?? savedNames.get(id) ?? id, game: gamesById.get(id) }))
        .sort((a, b) => a.name.localeCompare(b.name)),
    [selectedGameIds, gamesById, savedNames],
  );
  const notReady = selectionList.filter((entry) => entry.game !== undefined && !entry.game.isApworldReady);
  const readyCount = useMemo(() => allGames.filter((g) => g.isApworldReady).length, [allGames]);

  function edit(change: () => void) {
    change();
    if (save.kind !== "saving") setSave({ kind: "idle" });
  }

  function onFilterChange(change: () => void) {
    change();
    setPage(1);
  }

  function toggleGame(gameId: string) {
    edit(() =>
      setSelectedGameIds((prev) => {
        const next = new Set(prev);
        if (next.has(gameId)) next.delete(gameId);
        else next.add(gameId);
        return next;
      }),
    );
  }

  function removeGames(ids: Iterable<string>) {
    const drop = new Set(ids);
    edit(() => setSelectedGameIds((prev) => new Set([...prev].filter((id) => !drop.has(id)))));
  }

  function togglePage() {
    if (pageAllSelected) removeGames(pageItems.map((g) => g.id));
    else edit(() => setSelectedGameIds((prev) => withAllFiltered(prev, pageItems)));
  }

  function resetDraft() {
    if (saved === null) return;
    setEnabled(saved.enabled);
    setMaxGames(saved.maxGames);
    setSelectedGameIds(new Set(saved.gameIds));
    setSave({ kind: "idle" });
  }

  async function submit(formEvent: FormEvent<HTMLFormElement>) {
    formEvent.preventDefault();
    const draft: Draft = { enabled, maxGames, gameIds: new Set(selectedGameIds) };
    setSave({ kind: "saving" });
    try {
      const response = await apiFetch(`${env.apiBaseUrl}/admin/events/${eventId}/game-selection`, {
        body: JSON.stringify({
          gameSelectionEnabled: draft.enabled,
          gameSelectionMax: draft.maxGames,
          games: [...draft.gameIds].map((gameId) => ({ gameId })),
        }),
        headers: { "Content-Type": "application/json" },
        method: "PATCH",
      });
      if (!response.ok) {
        setSave({ kind: "error", message: "Impossible d'enregistrer la configuration." });
        return;
      }
      // Story 3.16: stay on the page - the admin often adjusts in several passes.
      setSaved(draft);
      // Story 3.17: the hub greys "Run et sessions" until the selection is on; let it see the change.
      void queryClient.invalidateQueries({ queryKey: ["admin-events"] });
      setSave({ kind: "saved" });
    } catch {
      setSave({ kind: "error", message: "Impossible de contacter l'API." });
    }
  }

  if (state.kind === "loading") {
    return (
      <section className="grid w-full gap-8 px-4 py-10">
        <div className="animate-pulse">
          <div className="h-4 w-32 rounded bg-surface-2" />
          <div className="mt-6 h-3.5 w-20 rounded bg-surface-2" />
          <div className="mt-2 h-9 w-64 rounded bg-surface-2" />
          <div className="mt-1.5 h-4 w-48 rounded bg-surface-2" />
        </div>
        <div className="h-20 animate-pulse rounded-lg border border-border bg-surface" />
        <div className="h-10 animate-pulse rounded border border-border bg-surface" />
        <div className="h-96 animate-pulse rounded-lg border border-border bg-surface" />
      </section>
    );
  }

  if (state.kind === "error") {
    return (
      <section className="grid w-full gap-8 px-4 py-10">
        <div className="grid justify-items-center gap-3 rounded-lg border border-border bg-surface p-8 text-center">
          <ShieldAlert aria-hidden="true" className="size-8 text-danger" />
          <p className="text-sm text-muted-foreground">{state.message}</p>
          <Link className="text-sm text-accent-text hover:underline" href="/admin/evenements">
            Retour aux événements
          </Link>
        </div>
      </section>
    );
  }

  const selectedCount = selectedGameIds.size;

  return (
    <section className="grid w-full gap-6 px-4 pb-0 pt-10">
      <header>
        <p className="mb-3 text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">Backoffice</p>
        <div className="flex items-center gap-3">
          <Gamepad2 aria-hidden="true" className="size-6 text-accent-text" />
          <div>
            <h1 className="font-heading text-4xl font-bold leading-tight text-foreground">Sélection de jeux</h1>
            {state.eventTitle ? <p className="mt-1 text-muted-foreground">{state.eventTitle}</p> : null}
          </div>
        </div>
      </header>

      <form className="grid gap-6" onSubmit={submit}>
        <div className="flex flex-wrap items-center gap-x-8 gap-y-4 rounded-lg border border-border bg-surface p-5">
          <label className="flex cursor-pointer select-none items-center gap-3">
            <Checkbox checked={enabled} onChange={(v) => edit(() => setEnabled(v))} />
            <span className="text-sm font-semibold text-foreground">Activer la sélection de jeux pour cet événement</span>
          </label>
          {enabled ? (
            <label className="flex flex-wrap items-center gap-3 text-sm font-semibold text-foreground">
              Limite de jeux par inscription
              <input
                className="min-h-9 w-24 rounded border border-border bg-background px-3 text-sm text-foreground outline-none focus:border-accent [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                min={1}
                placeholder="Illimité"
                type="number"
                value={maxGames ?? ""}
                onChange={(e) => {
                  const v = parseInt(e.target.value, 10);
                  edit(() => setMaxGames(Number.isFinite(v) && v > 0 ? v : null));
                }}
              />
              <span className="text-xs font-normal text-muted-foreground">Vide = illimité. Ne limite pas le catalogue.</span>
            </label>
          ) : null}
        </div>

        {enabled ? (
          <>
            {/* Mobile: catalogue and selection are two tabs; side by side from lg up. */}
            <div className="flex gap-1 rounded border border-border p-1 lg:hidden" role="tablist">
              <PaneTab active={pane === "catalogue"} onClick={() => setPane("catalogue")}>
                Catalogue ({filteredGames.length})
              </PaneTab>
              <PaneTab active={pane === "selection"} onClick={() => setPane("selection")}>
                Sélection ({selectedCount})
              </PaneTab>
            </div>

            <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
              <div className={cn("grid min-w-0 gap-3", pane !== "catalogue" && "hidden lg:grid")}>
                {/* Toolbar sticks under the admin's mobile top bar while scrolling the catalogue. */}
                <div className="sticky top-14 z-10 grid gap-2 border-b border-border bg-background py-2 md:top-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <input
                      aria-label="Rechercher un jeu"
                      className="min-h-10 min-w-48 flex-1 rounded border border-border bg-background px-3 text-sm outline-none focus:border-accent"
                      placeholder={`Rechercher parmi ${allGames.length} jeux (nom ou slug)…`}
                      type="search"
                      value={search}
                      onChange={(e) => onFilterChange(() => setSearch(e.target.value))}
                    />
                    <div className="flex gap-0.5 rounded border border-border p-0.5">
                      <ScopeButton active={scope === "available"} onClick={() => onFilterChange(() => setScope("available"))}>
                        Disponibles
                      </ScopeButton>
                      <ScopeButton
                        active={scope === "available_experimental"}
                        onClick={() => onFilterChange(() => setScope("available_experimental"))}
                      >
                        + Expérimentaux
                      </ScopeButton>
                    </div>
                  </div>
                  <div className="flex flex-wrap items-center gap-2">
                    <select
                      aria-label="Filtrer par apworld"
                      className={SELECT_CLASS}
                      value={apworldFilter}
                      onChange={(e) => onFilterChange(() => setApworldFilter(toApworldFilter(e.target.value)))}
                    >
                      <option value="all">Apworld : tous</option>
                      <option value="ready">Apworld prêt</option>
                      <option value="not_ready">Apworld non prêt</option>
                    </select>
                    <select
                      aria-label="Filtrer par plateforme"
                      className={SELECT_CLASS}
                      value={platform}
                      onChange={(e) => onFilterChange(() => setPlatform(e.target.value))}
                    >
                      <option value="">Plateforme : toutes</option>
                      {platformOptions.map((family) => (
                        <option key={family} value={family}>
                          {family}
                        </option>
                      ))}
                    </select>
                    <select
                      aria-label="Trier"
                      className={SELECT_CLASS}
                      value={sortDir}
                      onChange={(e) => onFilterChange(() => setSortDir(e.target.value === "desc" ? "desc" : "asc"))}
                    >
                      <option value="asc">Nom (A→Z)</option>
                      <option value="desc">Nom (Z→A)</option>
                    </select>
                    <button
                      aria-pressed={selectedOnly}
                      className={cn(
                        "inline-flex min-h-9 items-center gap-1.5 rounded border px-3 text-xs font-semibold transition-colors",
                        selectedOnly ? "border-accent bg-accent/10 text-accent-text" : "border-border text-muted-foreground hover:text-foreground",
                      )}
                      onClick={() => onFilterChange(() => setSelectedOnly((v) => !v))}
                      type="button"
                    >
                      <Check aria-hidden="true" className="size-3.5" />
                      Sélectionnés seulement
                    </button>
                    <BulkActions
                      allFilteredSelected={allFilteredSelected}
                      filteredCount={filteredGames.length}
                      filteredSelectedCount={filteredSelectedCount}
                      onAddReady={() => edit(() => setSelectedGameIds((prev) => withAllFiltered(prev, allGames.filter((g) => g.isApworldReady))))}
                      onClear={() => edit(() => setSelectedGameIds(new Set()))}
                      onDeselectFiltered={() => removeGames(filteredGames.map((g) => g.id))}
                      onSelectFiltered={() => edit(() => setSelectedGameIds((prev) => withAllFiltered(prev, filteredGames)))}
                      readyCount={readyCount}
                      selectedCount={selectedCount}
                    />
                  </div>
                  <p className="text-xs text-muted-foreground">
                    {filteredGames.length} jeu{filteredGames.length !== 1 ? "x" : ""} affiché{filteredGames.length !== 1 ? "s" : ""}
                    {filteredGames.length !== allGames.length ? ` sur ${allGames.length}` : ""}
                    {filteredSelectedCount > 0 ? `, dont ${filteredSelectedCount} sélectionné${filteredSelectedCount !== 1 ? "s" : ""}` : ""}
                  </p>
                </div>

                {filteredGames.length === 0 ? (
                  <div className="grid justify-items-center gap-3 rounded-lg border border-border bg-surface p-8 text-center">
                    <Gamepad2 aria-hidden="true" className="size-8 text-muted-foreground" />
                    <p className="text-sm text-muted-foreground">
                      {selectedOnly
                        ? "Aucun jeu sélectionné dans ces filtres."
                        : search
                          ? "Aucun jeu ne correspond à la recherche."
                          : "Aucun jeu dans cette catégorie."}
                    </p>
                  </div>
                ) : (
                  <>
                    <div className="overflow-x-auto rounded-lg border border-border bg-surface">
                      <table className="w-full border-collapse text-left text-sm">
                        <thead className="border-b border-border text-xs text-muted-foreground">
                          <tr>
                            <th className="w-px py-2 pl-3 pr-1">
                              <Checkbox
                                ariaLabel="Sélectionner les jeux de cette page"
                                checked={pageAllSelected}
                                indeterminate={pageSomeSelected}
                                onChange={togglePage}
                              />
                            </th>
                            <th className="w-12 px-2 py-2">
                              <span className="sr-only">Couverture</span>
                            </th>
                            <th className="px-2 py-2 font-medium">Jeu</th>
                            <th className="hidden px-2 py-2 font-medium sm:table-cell">Plateformes</th>
                            <th className="px-3 py-2 text-right font-medium">État</th>
                          </tr>
                        </thead>
                        <tbody>
                          {pageItems.map((game) => (
                            <CatalogueRow
                              game={game}
                              key={game.id}
                              selected={selectedGameIds.has(game.id)}
                              onToggle={() => toggleGame(game.id)}
                            />
                          ))}
                        </tbody>
                      </table>
                    </div>
                    {totalPages > 1 ? (
                      <nav aria-label="Pages du catalogue" className="flex items-center justify-between gap-2">
                        <button
                          className={PAGE_BUTTON_CLASS}
                          disabled={safePage === 1}
                          onClick={() => setPage(safePage - 1)}
                          type="button"
                        >
                          <ChevronLeft aria-hidden="true" className="size-3.5" />
                          Précédent
                        </button>
                        <span className="text-xs text-muted-foreground">
                          Page {safePage} / {totalPages}
                        </span>
                        <button
                          className={PAGE_BUTTON_CLASS}
                          disabled={safePage === totalPages}
                          onClick={() => setPage(safePage + 1)}
                          type="button"
                        >
                          Suivant
                          <ChevronRight aria-hidden="true" className="size-3.5" />
                        </button>
                      </nav>
                    ) : null}
                  </>
                )}
              </div>

              <aside
                aria-label="Jeux sélectionnés"
                className={cn(
                  "grid gap-3 rounded-lg border border-border bg-surface p-4 lg:sticky lg:top-4",
                  pane !== "selection" && "hidden lg:grid",
                )}
              >
                <div className="flex items-center justify-between gap-2">
                  <h2 className="text-sm font-semibold text-foreground">
                    Sélection <span className="font-normal text-muted-foreground">({selectedCount})</span>
                  </h2>
                  {selectedCount > 0 ? (
                    <button
                      className="text-xs text-muted-foreground transition-colors hover:text-danger"
                      onClick={() => edit(() => setSelectedGameIds(new Set()))}
                      type="button"
                    >
                      Tout retirer
                    </button>
                  ) : null}
                </div>
                {notReady.length > 0 ? (
                  <p className="flex gap-2 rounded border border-accent-warm/30 bg-accent-warm/10 p-2 text-xs text-accent-warm" role="status">
                    <AlertTriangle aria-hidden="true" className="mt-0.5 size-3.5 shrink-0" />
                    <span>
                      {notReady.length} jeu{notReady.length !== 1 ? "x" : ""} sans apworld prêt : les joueurs ne pourront pas
                      {notReady.length !== 1 ? " les" : " le"} configurer.
                    </span>
                  </p>
                ) : null}
                {selectedCount === 0 ? (
                  <p className="text-sm text-muted-foreground">Aucun jeu. Coche des jeux dans le catalogue.</p>
                ) : (
                  <ul className="grid max-h-[60vh] gap-0.5 overflow-y-auto pr-1" role="list">
                    {selectionList.map((entry) => (
                      <li className="flex items-center justify-between gap-2 rounded px-2 py-1 hover:bg-surface-2" key={entry.id}>
                        <span className="flex min-w-0 items-center gap-1.5 text-sm text-foreground">
                          {entry.game !== undefined && !entry.game.isApworldReady ? (
                            <AlertTriangle aria-label="Apworld non prêt" className="size-3.5 shrink-0 text-accent-warm" />
                          ) : null}
                          <span className="truncate">{entry.name}</span>
                        </span>
                        <button
                          aria-label={`Retirer ${entry.name}`}
                          className="inline-flex size-6 shrink-0 items-center justify-center rounded text-muted-foreground transition-colors hover:bg-danger/10 hover:text-danger"
                          onClick={() => removeGames([entry.id])}
                          type="button"
                        >
                          <X aria-hidden="true" className="size-3.5" />
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
              </aside>
            </div>
          </>
        ) : null}

        {/* Story 3.16: the save bar stays on screen, whatever the length of the catalogue. */}
        <div className="sticky bottom-0 z-20 -mx-4 flex flex-wrap items-center justify-between gap-3 border-t border-border bg-surface/95 px-4 py-3 backdrop-blur-sm">
          <p aria-live="polite" className="text-sm">
            <span className="font-semibold text-foreground">
              {selectedCount} jeu{selectedCount !== 1 ? "x" : ""} sélectionné{selectedCount !== 1 ? "s" : ""}
            </span>
            {save.kind === "error" ? (
              <span className="ml-2 text-danger" role="alert">
                {save.message}
              </span>
            ) : save.kind === "saved" && !dirty ? (
              <span className="ml-2 inline-flex items-center gap-1 text-success">
                <Check aria-hidden="true" className="size-3.5" />
                Enregistré
              </span>
            ) : dirty ? (
              <span className="ml-2 text-accent-warm">Modifications non enregistrées</span>
            ) : null}
          </p>
          <div className="flex gap-2">
            {dirty ? (
              <button
                className="inline-flex min-h-10 items-center justify-center rounded border border-border px-4 text-sm font-semibold text-foreground hover:border-accent"
                disabled={save.kind === "saving"}
                onClick={resetDraft}
                type="button"
              >
                Annuler les modifications
              </button>
            ) : null}
            <button
              className="inline-flex min-h-10 items-center justify-center rounded bg-accent px-5 text-sm font-semibold text-white hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-50"
              disabled={!dirty || save.kind === "saving"}
              type="submit"
            >
              {save.kind === "saving" ? "Enregistrement…" : "Enregistrer"}
            </button>
          </div>
        </div>
      </form>
    </section>
  );
}

const SELECT_CLASS =
  "min-h-9 rounded border border-border bg-background px-2.5 text-xs text-foreground outline-none focus:border-accent";
const PAGE_BUTTON_CLASS =
  "inline-flex min-h-9 items-center justify-center gap-1 rounded border border-border px-3 text-xs font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-40";

function toApworldFilter(value: string): ApworldFilter {
  return value === "ready" || value === "not_ready" ? value : "all";
}

function CatalogueRow({ game, selected, onToggle }: { game: AvailableGame; selected: boolean; onToggle: () => void }) {
  return (
    <tr
      className={cn(
        "cursor-pointer border-b border-border transition-colors last:border-b-0 hover:bg-surface-2",
        selected && "bg-accent/10",
      )}
      onClick={onToggle}
    >
      <td className="py-1.5 pl-3 pr-1">
        <Checkbox
          ariaLabel={`Sélectionner ${game.name}`}
          checked={selected}
          onChange={onToggle}
          onClick={(e: React.MouseEvent) => e.stopPropagation()}
        />
      </td>
      <td className="px-2 py-1.5">
        {game.coverImageUrl ? (
          <ThumbnailPreview src={game.coverImageUrl} />
        ) : (
          <div className="h-10 w-7 rounded border border-border bg-surface-2" />
        )}
      </td>
      <td className="px-2 py-1.5">
        <span className="font-semibold text-foreground">{game.name}</span>
        <span className="ml-2 hidden font-mono text-xs text-muted-foreground md:inline">{game.slug}</span>
      </td>
      <td className="hidden px-2 py-1.5 text-xs text-muted-foreground sm:table-cell">{game.platforms.join(", ")}</td>
      <td className="px-3 py-1.5 text-right">
        <span className="inline-flex flex-wrap justify-end gap-1">
          {game.availability === "experimental" ? <Badge tone="warm">Expérimental</Badge> : null}
          {game.isApworldReady ? <Badge tone="success">Apworld prêt</Badge> : <Badge tone="muted">Apworld non prêt</Badge>}
        </span>
      </td>
    </tr>
  );
}

function Badge({ tone, children }: { tone: "success" | "warm" | "muted"; children: React.ReactNode }) {
  return (
    <span
      className={cn(
        "whitespace-nowrap rounded-full border px-2 py-0.5 text-[11px] font-medium",
        tone === "success" && "border-success/30 bg-success/10 text-success",
        tone === "warm" && "border-accent-warm/30 bg-accent-warm/10 text-accent-warm",
        tone === "muted" && "border-border text-muted-foreground",
      )}
    >
      {children}
    </span>
  );
}

function ScopeButton({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      aria-pressed={active}
      className={cn(
        "rounded px-2.5 py-1 text-xs font-semibold transition-colors",
        active ? "bg-accent text-white" : "text-muted-foreground hover:text-foreground",
      )}
      onClick={onClick}
      type="button"
    >
      {children}
    </button>
  );
}

function PaneTab({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      aria-selected={active}
      className={cn(
        "flex-1 rounded px-3 py-1.5 text-sm font-semibold transition-colors",
        active ? "bg-accent text-white" : "text-muted-foreground hover:text-foreground",
      )}
      onClick={onClick}
      role="tab"
      type="button"
    >
      {children}
    </button>
  );
}

type BulkActionsProps = {
  filteredCount: number;
  filteredSelectedCount: number;
  allFilteredSelected: boolean;
  selectedCount: number;
  readyCount: number;
  onSelectFiltered: () => void;
  onDeselectFiltered: () => void;
  onAddReady: () => void;
  onClear: () => void;
};

/** The bulk edits, gathered in one menu so they no longer crowd the toolbar. */
function BulkActions(props: BulkActionsProps) {
  const ref = useRef<HTMLDetailsElement>(null);
  function run(action: () => void) {
    action();
    if (ref.current) ref.current.open = false;
  }
  return (
    <details className="relative" ref={ref}>
      <summary className="inline-flex min-h-9 cursor-pointer list-none items-center gap-1.5 rounded border border-border px-3 text-xs font-semibold text-foreground transition-colors hover:border-accent [&::-webkit-details-marker]:hidden">
        Actions groupées
        <ChevronDown aria-hidden="true" className="size-3.5" />
      </summary>
      <div className="absolute right-0 z-30 mt-1 grid w-72 gap-0.5 rounded-lg border border-border bg-surface p-1 shadow-lg">
        <MenuItem
          disabled={props.filteredCount === 0 || props.allFilteredSelected}
          icon={<ListChecks aria-hidden="true" className="size-3.5" />}
          onClick={() => run(props.onSelectFiltered)}
        >
          Sélectionner les résultats ({props.filteredCount})
        </MenuItem>
        <MenuItem
          disabled={props.filteredSelectedCount === 0}
          icon={<ListX aria-hidden="true" className="size-3.5" />}
          onClick={() => run(props.onDeselectFiltered)}
        >
          Désélectionner les résultats ({props.filteredSelectedCount})
        </MenuItem>
        <MenuItem
          disabled={props.readyCount === 0}
          icon={<Sparkles aria-hidden="true" className="size-3.5" />}
          onClick={() => run(props.onAddReady)}
        >
          Ajouter tous les jeux à apworld prêt ({props.readyCount})
        </MenuItem>
        <MenuItem
          danger
          disabled={props.selectedCount === 0}
          icon={<Trash2 aria-hidden="true" className="size-3.5" />}
          onClick={() => run(props.onClear)}
        >
          Vider la sélection
        </MenuItem>
      </div>
    </details>
  );
}

function MenuItem({
  children,
  icon,
  disabled,
  danger = false,
  onClick,
}: {
  children: React.ReactNode;
  icon: React.ReactNode;
  disabled: boolean;
  danger?: boolean;
  onClick: () => void;
}) {
  return (
    <button
      className={cn(
        "flex items-center gap-2 rounded px-2.5 py-2 text-left text-xs font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-40",
        danger ? "text-danger hover:bg-danger/10" : "text-foreground hover:bg-surface-2",
      )}
      disabled={disabled}
      onClick={onClick}
      type="button"
    >
      {icon}
      {children}
    </button>
  );
}

function Checkbox({
  checked,
  onChange,
  onClick,
  indeterminate = false,
  ariaLabel,
}: {
  checked: boolean;
  onChange: (checked: boolean) => void;
  onClick?: (e: React.MouseEvent) => void;
  indeterminate?: boolean;
  ariaLabel?: string;
}) {
  const ref = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (ref.current) {
      ref.current.indeterminate = indeterminate && !checked;
    }
  }, [indeterminate, checked]);

  return (
    <span className="relative inline-flex shrink-0">
      <input
        ref={ref}
        aria-label={ariaLabel}
        checked={checked}
        className="peer sr-only"
        type="checkbox"
        onChange={(e) => onChange(e.target.checked)}
        onClick={onClick}
      />
      <span className="flex size-5 items-center justify-center rounded border border-border bg-background transition-colors peer-checked:border-accent peer-checked:bg-accent peer-focus-visible:ring-2 peer-focus-visible:ring-accent peer-focus-visible:ring-offset-1 peer-focus-visible:ring-offset-background">
        {checked ? (
          <Check aria-hidden="true" className="size-3 text-white" strokeWidth={3} />
        ) : indeterminate ? (
          <Minus aria-hidden="true" className="size-3 text-accent-text" strokeWidth={3} />
        ) : null}
      </span>
    </span>
  );
}

/** Story 3.14: the catalogue has no cap - the slot limit only bounds what one registrant picks. */
export function withAllFiltered(selected: ReadonlySet<string>, filtered: readonly { id: string }[]): Set<string> {
  const next = new Set(selected);
  for (const game of filtered) {
    next.add(game.id);
  }
  return next;
}

/** Story 3.16: one page of the catalogue; a page past the end (after a filter shrank the list) falls back to the last. */
export function paginate<T>(items: readonly T[], page: number, size: number): { pageItems: T[]; safePage: number; totalPages: number } {
  const totalPages = Math.max(1, Math.ceil(items.length / size));
  const safePage = Math.min(Math.max(1, page), totalPages);
  return { pageItems: items.slice((safePage - 1) * size, safePage * size), safePage, totalPages };
}

/** Story 3.16: whether the draft differs from what was last saved. */
export function isDraftDirty(saved: Draft, current: Draft): boolean {
  if (saved.enabled !== current.enabled || saved.maxGames !== current.maxGames) return true;
  if (saved.gameIds.size !== current.gameIds.size) return true;
  for (const id of current.gameIds) {
    if (!saved.gameIds.has(id)) return true;
  }
  return false;
}
