"use client";

import {
  AlertTriangle,
  ArrowRight,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  ChevronsUpDown,
  ExternalLink,
  EyeOff,
  Info,
  Loader2,
  Lock,
  Package,
  RefreshCw,
  Settings2,
  ShieldAlert,
  Shuffle,
  Tag,
  ThumbsUp,
} from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import {
  APWORLD_UPDATE_STATUSES,
  updateStatusLabel,
  updateStatusTone,
  type ApworldUpdateStatusTone,
} from "./apworld-update-status";
import {
  EMPTY_CHANGES_FILTER,
  EMPTY_NEW_GAMES_FILTER,
  EMPTY_UPDATES_FILTER,
  filterChanges,
  filterIgnored,
  filterNewGames,
  filterRemoved,
  filterUpdates,
  type ChangesFilter,
  type NewGamesFilter,
  type UpdatesFilter,
} from "./admin-catalogue-filters";
import {
  checkApworldUpdates,
  fetchAdminGameBase,
  fetchCatalogSync,
  fetchGameRequestVotes,
  ignoreCatalogGame,
  unignoreCatalogGame,
  updateGameAvailability,
  type ApworldUpdate,
  type CatalogSyncData,
  type CatalogSyncResult,
  type IgnoredGame,
  type NewGame,
  type RemovedGame,
  type StabilityChange,
} from "./admin-catalogue-sync-api";

const PAGE_SIZE = 20;

const SYNC_QUERY_KEY = ["admin-catalog-sync"] as const;
const VOTES_QUERY_KEY = ["admin-game-request-votes"] as const;

type CatalogueTab = "new" | "updates" | "stability" | "ignored" | "removed";

const TABS: { key: CatalogueTab; label: string }[] = [
  { key: "new", label: "Nouveaux jeux" },
  { key: "updates", label: "Mises à jour APWorld" },
  { key: "stability", label: "Stabilité modifiée" },
  { key: "ignored", label: "Ignorés" },
  { key: "removed", label: "Retirés du sheet" },
];

const AVAILABILITY_OPTIONS = [
  { value: "available", label: "Disponible" },
  { value: "experimental", label: "Expérimental" },
  { value: "unavailable", label: "Indisponible" },
];

function withVotes(data: CatalogSyncData, votes: Map<string, number>): CatalogSyncData {
  return {
    ...data,
    newGames: data.newGames.map((g) => ({
      ...g,
      votes: votes.get(g.name.toLowerCase().trim()) ?? 0,
    })),
  };
}

// ── Main component ────────────────────────────────────────────────────────────

export function AdminCatalogueSyncPage() {
  const queryClient = useQueryClient();
  const [syncing, setSyncing] = useState(false);
  const [checkingUpdates, setCheckingUpdates] = useState(false);
  const [applyingId, setApplyingId] = useState<string | null>(null);
  const [ignoringName, setIgnoringName] = useState<string | null>(null);
  const [flash, setFlash] = useState<{ kind: "success" | "error"; text: string } | null>(null);
  const [tab, setTab] = useState<CatalogueTab>("new");
  const [newGamesFilter, setNewGamesFilter] = useState<NewGamesFilter>(EMPTY_NEW_GAMES_FILTER);
  const [updatesFilter, setUpdatesFilter] = useState<UpdatesFilter>(EMPTY_UPDATES_FILTER);
  const [changesFilter, setChangesFilter] = useState<ChangesFilter>(EMPTY_CHANGES_FILTER);
  const [ignoredFilter, setIgnoredFilter] = useState("");
  const [removedFilter, setRemovedFilter] = useState("");
  const [newGamesPage, setNewGamesPage] = useState(1);
  const [updatesPage, setUpdatesPage] = useState(1);
  const [changesPage, setChangesPage] = useState(1);

  // fetchCatalogSync never throws (the 503 "Google Sheets down" message and other failures are
  // encoded in the result's `kind`), so the query never errors and never retries.
  const syncQuery = useQuery({
    queryKey: SYNC_QUERY_KEY,
    queryFn: () => fetchCatalogSync(),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  const votesQuery = useQuery({
    queryKey: VOTES_QUERY_KEY,
    queryFn: fetchGameRequestVotes,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  const loading = syncQuery.isPending || votesQuery.isPending;
  const syncResult = syncQuery.data;
  const data: CatalogSyncData | null =
    !loading && syncResult !== undefined && syncResult.kind === "ready"
      ? withVotes(syncResult.data, votesQuery.data ?? new Map<string, number>())
      : null;

  // Actions used to rebuild the whole page state via reloadAll(); they now invalidate both query
  // keys instead so TanStack refetches the diff and the vote counts.
  async function refreshAll(): Promise<void> {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: SYNC_QUERY_KEY }),
      queryClient.invalidateQueries({ queryKey: VOTES_QUERY_KEY }),
    ]);
  }

  async function handleSync(): Promise<void> {
    setSyncing(true);
    try {
      // `force=true` busts the api-side sheet cache - a plain invalidate would refetch without it -
      // so the forced result is written straight into the cache.
      const result: CatalogSyncResult = await fetchCatalogSync(true);
      queryClient.setQueryData(SYNC_QUERY_KEY, result);
      await queryClient.invalidateQueries({ queryKey: VOTES_QUERY_KEY });
    } finally {
      setSyncing(false);
    }
  }

  async function handleCheckUpdates(): Promise<void> {
    setCheckingUpdates(true);
    setFlash(null);
    try {
      const summary = await checkApworldUpdates();
      if (summary === null) {
        setFlash({ kind: "error", text: "La vérification des mises à jour a échoué." });
        return;
      }
      const extra = summary.rateLimitHit ? " (limite GitHub atteinte)" : "";
      setFlash({ kind: "success", text: `${summary.checked} jeu(x) vérifié(s)${extra}.` });
      await refreshAll();
    } finally {
      setCheckingUpdates(false);
    }
  }

  async function handleApply(gameId: string, newAvailability: string): Promise<void> {
    setApplyingId(gameId);
    setFlash(null);
    try {
      const game = await fetchAdminGameBase(gameId);
      if (game === null) {
        setFlash({ kind: "error", text: "Impossible de récupérer les données du jeu." });
        return;
      }

      const patched = await updateGameAvailability(gameId, game, newAvailability);
      if (!patched) {
        setFlash({ kind: "error", text: "Impossible d'appliquer le changement de disponibilité." });
        return;
      }

      setFlash({ kind: "success", text: `Disponibilité mise à jour : ${availabilityLabel(newAvailability)}.` });
      await refreshAll();
    } finally {
      setApplyingId(null);
    }
  }

  async function handleIgnore(name: string): Promise<void> {
    setIgnoringName(name);
    setFlash(null);
    try {
      const ok = await ignoreCatalogGame(name);
      if (!ok) {
        setFlash({ kind: "error", text: "Impossible d'ignorer ce jeu." });
        return;
      }
      await refreshAll();
    } finally {
      setIgnoringName(null);
    }
  }

  async function handleUnignore(name: string): Promise<void> {
    setIgnoringName(name);
    setFlash(null);
    try {
      const ok = await unignoreCatalogGame(name);
      if (!ok) {
        setFlash({ kind: "error", text: "Impossible de retirer l'état ignoré." });
        return;
      }
      await refreshAll();
    } finally {
      setIgnoringName(null);
    }
  }

  const allTrackedUpdates = data?.apworldUpdates.filter((u) => u.updateStatus !== "not_tracked") ?? [];
  const counts: Record<CatalogueTab, number> = {
    new: data?.newGames.length ?? 0,
    updates: allTrackedUpdates.length,
    stability: data?.stabilityChanged.length ?? 0,
    ignored: data?.ignoredGames.length ?? 0,
    removed: data?.removedFromSheet.length ?? 0,
  };

  return (
    <section className="grid w-full min-w-0 grid-cols-1 gap-8 px-4 py-10">
      <header>
        <p className="mb-3 text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">Backoffice</p>
        <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
          <div>
            <h1 className="font-heading text-4xl font-bold leading-tight text-foreground">
              Synchronisation catalogue
            </h1>
            <p className="mt-3 max-w-2xl text-muted-foreground">
              Diff entre le Google Sheet communautaire et la bibliothèque de jeux.
            </p>
          </div>
          <div className="flex flex-wrap items-start gap-2">
            {data && !data.googleApiAvailable ? (
              <span className="inline-flex items-center gap-1.5 rounded border border-warning/50 bg-warning/10 px-2.5 py-1 text-xs font-medium text-warning">
                <AlertTriangle aria-hidden="true" className="size-3.5" />
                API Google indisponible
              </span>
            ) : null}
            {data && !data.githubChecksAvailable ? (
              <span className="inline-flex items-center gap-1.5 rounded border border-border bg-surface-2 px-2.5 py-1 text-xs font-medium text-muted-foreground">
                <Info aria-hidden="true" className="size-3.5" />
                GITHUB_TOKEN absent
              </span>
            ) : null}
            <button
              className="inline-flex min-h-9 items-center gap-2 rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-50"
              disabled={syncing || loading}
              type="button"
              onClick={() => void handleSync()}
            >
              {syncing ? (
                <Loader2 aria-hidden="true" className="size-4 animate-spin" />
              ) : (
                <RefreshCw aria-hidden="true" className="size-4" />
              )}
              Synchro. feuille
            </button>
          </div>
        </div>
        {data?.cachedAt ? (
          <p className="mt-2 text-xs text-muted-foreground">
            Dernière synchro :{" "}
            {new Intl.DateTimeFormat("fr-FR", { dateStyle: "medium", timeStyle: "short" }).format(
              new Date(data.cachedAt),
            )}
          </p>
        ) : null}
      </header>

      {flash ? (
        <p
          className={`rounded border p-3 text-sm ${flash.kind === "success" ? "border-success/50 bg-success/5 text-success" : "border-danger/50 bg-danger/5 text-danger"}`}
          role={flash.kind === "success" ? "status" : "alert"}
        >
          {flash.text}
        </p>
      ) : null}

      {loading ? (
        <LoadingSkeleton />
      ) : data === null ? (
        <ErrorPanel
          message={
            syncResult !== undefined && syncResult.kind === "error"
              ? syncResult.message
              : "Impossible de charger la synchronisation catalogue."
          }
          onRetry={() => void handleSync()}
        />
      ) : (
        <div className="grid min-w-0 gap-6">
          <div className="flex flex-wrap gap-2 border-b border-border" role="tablist">
            {TABS.map((t) => (
              <button
                aria-selected={tab === t.key}
                className={`-mb-px inline-flex min-h-10 items-center gap-2 border-b-2 px-4 text-sm font-semibold transition-colors ${tab === t.key ? "border-accent text-foreground" : "border-transparent text-muted-foreground hover:text-foreground"}`}
                key={t.key}
                onClick={() => setTab(t.key)}
                role="tab"
                type="button"
              >
                {t.label}
                <span className="font-mono text-xs font-normal text-muted-foreground">{counts[t.key]}</span>
              </button>
            ))}
          </div>

          <div role="tabpanel">
            {tab === "new" ? (
              <NewGamesTable
                games={filterNewGames(data.newGames, newGamesFilter)}
                totalCount={data.newGames.length}
                filter={newGamesFilter}
                onFilterChange={(f) => {
                  setNewGamesFilter(f);
                  setNewGamesPage(1);
                }}
                page={newGamesPage}
                onPageChange={setNewGamesPage}
                ignoringName={ignoringName}
                onIgnore={(name) => void handleIgnore(name)}
              />
            ) : null}
            {tab === "updates" ? (
              <ApworldUpdatesTable
                updates={filterUpdates(allTrackedUpdates, updatesFilter)}
                totalCount={allTrackedUpdates.length}
                filter={updatesFilter}
                onFilterChange={(f) => {
                  setUpdatesFilter(f);
                  setUpdatesPage(1);
                }}
                githubChecksAvailable={data.githubChecksAvailable}
                checkingUpdates={checkingUpdates}
                onCheckUpdates={() => void handleCheckUpdates()}
                page={updatesPage}
                onPageChange={setUpdatesPage}
              />
            ) : null}
            {tab === "stability" ? (
              <StabilityChangedTable
                changes={filterChanges(data.stabilityChanged, changesFilter)}
                totalCount={data.stabilityChanged.length}
                filter={changesFilter}
                onFilterChange={(f) => {
                  setChangesFilter(f);
                  setChangesPage(1);
                }}
                applyingId={applyingId}
                onApply={(id, av) => void handleApply(id, av)}
                page={changesPage}
                onPageChange={setChangesPage}
              />
            ) : null}
            {tab === "ignored" ? (
              <IgnoredGamesTable
                games={filterIgnored(data.ignoredGames, ignoredFilter)}
                totalCount={data.ignoredGames.length}
                filter={ignoredFilter}
                onFilterChange={setIgnoredFilter}
                ignoringName={ignoringName}
                onUnignore={(name) => void handleUnignore(name)}
              />
            ) : null}
            {tab === "removed" ? (
              <RemovedFromSheetTable
                games={filterRemoved(data.removedFromSheet, removedFilter)}
                totalCount={data.removedFromSheet.length}
                filter={removedFilter}
                onFilterChange={setRemovedFilter}
              />
            ) : null}
          </div>
        </div>
      )}
    </section>
  );
}

// ── Sections (table-based) ────────────────────────────────────────────────────

function NewGamesTable({
  games,
  totalCount,
  filter,
  onFilterChange,
  page,
  onPageChange,
  ignoringName,
  onIgnore,
}: {
  games: NewGame[];
  totalCount: number;
  filter: NewGamesFilter;
  onFilterChange: (f: NewGamesFilter) => void;
  page: number;
  onPageChange: (p: number) => void;
  ignoringName: string | null;
  onIgnore: (name: string) => void;
}) {
  const [sort, setSort] = useState<{ col: "name" | "votes"; dir: "asc" | "desc" }>({
    col: "votes",
    dir: "desc",
  });

  function toggleSort(col: "name" | "votes") {
    setSort((s) =>
      s.col === col
        ? { col, dir: s.dir === "asc" ? "desc" : "asc" }
        : { col, dir: col === "votes" ? "desc" : "asc" },
    );
    onPageChange(1);
  }

  const sorted = [...games].sort((a, b) => {
    if (sort.col === "votes") {
      return sort.dir === "desc" ? b.votes - a.votes : a.votes - b.votes;
    }
    const cmp = a.name.localeCompare(b.name, "fr");
    return sort.dir === "asc" ? cmp : -cmp;
  });

  const pageItems = sorted.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);

  return (
    <TableCard
      icon={
        <Package
          aria-hidden="true"
          className={`size-4 ${totalCount > 0 ? "text-accent-text" : "text-muted-foreground"}`}
        />
      }
      title="Nouveaux jeux"
      count={totalCount}
    >
      {totalCount === 0 ? (
        <p className="px-4 py-8 text-center text-sm text-muted-foreground">Aucun nouveau jeu dans le sheet.</p>
      ) : (
        <div className="min-w-0 overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border">
                <ThSortable col="name" sort={sort} onSort={toggleSort}>Nom</ThSortable>
                <Th>Disponibilité</Th>
                <Th>Flags</Th>
                <ThSortable col="votes" sort={sort} onSort={toggleSort}>Votes</ThSortable>
                <Th>Liens</Th>
                <Th right>Action</Th>
              </tr>
              <FilterRow>
                <FilterText label="Filtrer par nom" value={filter.name} onChange={(name) => onFilterChange({ ...filter, name })} />
                <FilterSelect
                  label="Filtrer par disponibilité"
                  options={AVAILABILITY_OPTIONS}
                  value={filter.availability}
                  onChange={(availability) => onFilterChange({ ...filter, availability })}
                />
                <FilterSelect
                  label="Filtrer par flags"
                  options={[
                    { value: "adult", label: "18+" },
                    { value: "bundled", label: "AP intégré" },
                    { value: "none", label: "Aucun" },
                  ]}
                  value={filter.flag}
                  onChange={(flag) => onFilterChange({ ...filter, flag: asNewGamesFlag(flag) })}
                />
                <FilterSelect
                  label="Filtrer par votes"
                  options={[
                    { value: "with", label: "Avec votes" },
                    { value: "without", label: "Sans vote" },
                  ]}
                  value={filter.votes}
                  onChange={(votes) => onFilterChange({ ...filter, votes: votes === "with" || votes === "without" ? votes : "" })}
                />
                <td />
                <td />
              </FilterRow>
            </thead>
            <tbody className="divide-y divide-border">
              {games.length === 0 ? <NoResultRow colSpan={6} /> : null}
              {pageItems.map((game) => (
                <tr className="transition-colors hover:bg-surface-2/40" key={game.name}>
                  <td className="px-4 py-3 font-semibold text-foreground">{game.name}</td>
                  <td className="px-4 py-3">
                    <AvailabilityBadge availability={game.availability} />
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex flex-wrap gap-1.5">
                      {game.adultContent ? (
                        <span className="inline-flex items-center rounded border border-danger/40 bg-danger/10 px-2 py-0.5 text-xs font-semibold text-danger">
                          18+
                        </span>
                      ) : null}
                      {game.bundledWithAp ? (
                        <span className="inline-flex items-center rounded border border-border bg-surface-2 px-2 py-0.5 text-xs font-semibold text-muted-foreground">
                          AP intégré
                        </span>
                      ) : null}
                      {!game.adultContent && !game.bundledWithAp ? (
                        <span className="text-xs text-muted-foreground/40">-</span>
                      ) : null}
                    </div>
                  </td>
                  <td className="px-4 py-3">
                    {game.votes > 0 ? (
                      <span className="inline-flex items-center gap-1 text-xs font-semibold text-accent-text">
                        <ThumbsUp aria-hidden="true" className="size-3" />
                        {game.votes}
                      </span>
                    ) : (
                      <span className="text-xs text-muted-foreground/40">-</span>
                    )}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex flex-wrap gap-2">
                      {game.links.map((link, j) =>
                        link.url ? (
                          <a
                            className="inline-flex items-center gap-1 text-xs text-accent-text hover:underline"
                            href={link.url}
                            key={j}
                            rel="noopener"
                            target="_blank"
                          >
                            {link.label}
                            <ExternalLink aria-hidden="true" className="size-3" />
                          </a>
                        ) : (
                          <span className="text-xs text-muted-foreground/50" key={j}>
                            {link.label}
                          </span>
                        ),
                      )}
                    </div>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex items-center justify-end gap-2">
                      <button
                        className="inline-flex min-h-8 items-center gap-1.5 rounded border border-border px-3 text-xs font-semibold text-muted-foreground transition-colors hover:border-border hover:text-foreground disabled:cursor-not-allowed disabled:opacity-40"
                        disabled={ignoringName === game.name}
                        title="Ne plus afficher ce jeu dans les nouveaux"
                        type="button"
                        onClick={() => onIgnore(game.name)}
                      >
                        {ignoringName === game.name ? (
                          <Loader2 aria-hidden="true" className="size-3.5 animate-spin" />
                        ) : (
                          <EyeOff aria-hidden="true" className="size-3.5" />
                        )}
                        Ignorer
                      </button>
                      <Link
                        className="inline-flex min-h-8 items-center gap-1.5 rounded border border-border px-3 text-xs font-semibold text-foreground transition-colors hover:border-accent"
                        href={`/admin/jeux/nouveau?name=${encodeURIComponent(game.name)}&availability=${encodeURIComponent(game.availability)}&adult=${game.adultContent ? "1" : "0"}&bundled=${game.bundledWithAp ? "1" : "0"}&links=${encodeURIComponent(JSON.stringify(game.links))}`}
                      >
                        Créer
                      </Link>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <Pagination total={games.length} page={page} pageSize={PAGE_SIZE} onPageChange={onPageChange} />
        </div>
      )}
    </TableCard>
  );
}

function ApworldUpdatesTable({
  updates,
  totalCount,
  filter,
  onFilterChange,
  githubChecksAvailable,
  checkingUpdates,
  onCheckUpdates,
  page,
  onPageChange,
}: {
  updates: ApworldUpdate[];
  totalCount: number;
  filter: UpdatesFilter;
  onFilterChange: (f: UpdatesFilter) => void;
  githubChecksAvailable: boolean;
  checkingUpdates: boolean;
  onCheckUpdates: () => void;
  page: number;
  onPageChange: (p: number) => void;
}) {
  const pageItems = updates.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);

  return (
    <TableCard
      icon={<Shuffle aria-hidden="true" className="size-4 text-accent-text" />}
      title="Mises à jour APWorld"
      count={totalCount}
      action={
        <button
          className="inline-flex min-h-8 items-center gap-2 rounded border border-border px-3 text-xs font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-40"
          disabled={!githubChecksAvailable || checkingUpdates}
          title={
            githubChecksAvailable
              ? undefined
              : "Configurez GITHUB_TOKEN pour activer les vérifications de version"
          }
          type="button"
          onClick={onCheckUpdates}
        >
          {checkingUpdates ? (
            <Loader2 aria-hidden="true" className="size-3.5 animate-spin" />
          ) : (
            <RefreshCw aria-hidden="true" className="size-3.5" />
          )}
          Vérifier les mises à jour
        </button>
      }
    >
      {totalCount === 0 ? (
        <p className="px-4 py-8 text-center text-sm text-muted-foreground">Aucun jeu suivi.</p>
      ) : (
        <div className="min-w-0 overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border">
                <Th>Jeu</Th>
                <Th>Statut</Th>
                <Th>Versions</Th>
                <Th>Publiée le</Th>
                <Th right>Liens</Th>
              </tr>
              <FilterRow>
                <FilterText label="Filtrer par nom" value={filter.name} onChange={(name) => onFilterChange({ ...filter, name })} />
                <FilterSelect
                  label="Filtrer par statut"
                  options={APWORLD_UPDATE_STATUSES.filter((s) => s !== "not_tracked").map((s) => ({
                    value: s,
                    label: updateStatusLabel(s),
                  }))}
                  value={filter.status}
                  onChange={(status) => onFilterChange({ ...filter, status })}
                />
                <FilterSelect
                  label="Filtrer par versions"
                  options={[
                    { value: "different", label: "Version différente" },
                    { value: "same", label: "Même version" },
                  ]}
                  value={filter.versions}
                  onChange={(versions) =>
                    onFilterChange({ ...filter, versions: versions === "different" || versions === "same" ? versions : "" })
                  }
                />
                <td />
                <td />
              </FilterRow>
            </thead>
            <tbody className="divide-y divide-border">
              {updates.length === 0 ? <NoResultRow colSpan={5} /> : null}
              {pageItems.map((update) => (
                <tr className="transition-colors hover:bg-surface-2/40" key={update.gameId}>
                  <td className="px-4 py-3 font-semibold text-foreground">{update.gameName}</td>
                  <td className="px-4 py-3">
                    <UpdateStatusBadge status={update.updateStatus} />
                  </td>
                  <td className="px-4 py-3 font-mono text-xs text-muted-foreground">
                    {update.deployedVersion ?? "-"}
                    <ArrowRight aria-hidden="true" className="mx-1 inline size-3" />
                    {update.latestVersion ?? "-"}
                  </td>
                  <td className="px-4 py-3 text-xs text-muted-foreground">
                    {update.publishedAt
                      ? new Intl.DateTimeFormat("fr-FR", { dateStyle: "short" }).format(
                          new Date(update.publishedAt),
                        )
                      : "-"}
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex flex-wrap items-center justify-end gap-3">
                      {update.releaseUrl ? (
                        <a
                          className="inline-flex items-center gap-1 text-xs text-accent-text hover:underline"
                          href={update.releaseUrl}
                          rel="noopener"
                          target="_blank"
                        >
                          Release
                          <ExternalLink aria-hidden="true" className="size-3" />
                        </a>
                      ) : null}
                      <Link
                        className="inline-flex items-center gap-1 text-xs text-accent-text hover:underline"
                        href={`/admin/jeux/${update.gameId}`}
                      >
                        Configurer
                        <Settings2 aria-hidden="true" className="size-3" />
                      </Link>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <Pagination total={updates.length} page={page} pageSize={PAGE_SIZE} onPageChange={onPageChange} />
        </div>
      )}
    </TableCard>
  );
}

function StabilityChangedTable({
  changes,
  totalCount,
  filter,
  onFilterChange,
  applyingId,
  onApply,
  page,
  onPageChange,
}: {
  changes: StabilityChange[];
  totalCount: number;
  filter: ChangesFilter;
  onFilterChange: (f: ChangesFilter) => void;
  applyingId: string | null;
  onApply: (gameId: string, newAvailability: string) => void;
  page: number;
  onPageChange: (p: number) => void;
}) {
  const pageItems = changes.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);

  return (
    <TableCard
      icon={
        <CheckCircle2
          aria-hidden="true"
          className={`size-4 ${totalCount > 0 ? "text-warning" : "text-muted-foreground"}`}
        />
      }
      title="Stabilité modifiée"
      count={totalCount}
    >
      {totalCount === 0 ? (
        <p className="px-4 py-8 text-center text-sm text-muted-foreground">
          Aucun changement de stabilité détecté.
        </p>
      ) : (
        <div className="min-w-0 overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border">
                <Th>Jeu</Th>
                <Th>Changement</Th>
                <Th right>Action</Th>
              </tr>
              <FilterRow>
                <td className="px-4 py-2">
                  <div className="grid gap-2 sm:grid-cols-[1fr_auto]">
                    <FilterTextInput
                      label="Filtrer par nom"
                      value={filter.name}
                      onChange={(name) => onFilterChange({ ...filter, name })}
                    />
                    <FilterSelectInput
                      allLabel="Verrou : tous"
                      label="Filtrer par verrouillage"
                      options={[
                        { value: "locked", label: "Verrouillé" },
                        { value: "unlocked", label: "Non verrouillé" },
                      ]}
                      value={filter.locked}
                      onChange={(locked) =>
                        onFilterChange({ ...filter, locked: locked === "locked" || locked === "unlocked" ? locked : "" })
                      }
                    />
                  </div>
                </td>
                <td className="px-4 py-2">
                  <div className="flex items-center gap-2">
                    <div className="min-w-28 flex-1">
                      <FilterSelectInput
                        label="Filtrer par disponibilité actuelle"
                        options={AVAILABILITY_OPTIONS}
                        value={filter.current}
                        onChange={(current) => onFilterChange({ ...filter, current })}
                      />
                    </div>
                    <ArrowRight aria-hidden="true" className="size-3 shrink-0 text-muted-foreground" />
                    <div className="min-w-28 flex-1">
                      <FilterSelectInput
                        label="Filtrer par nouvelle disponibilité"
                        options={AVAILABILITY_OPTIONS}
                        value={filter.next}
                        onChange={(next) => onFilterChange({ ...filter, next })}
                      />
                    </div>
                  </div>
                </td>
                <td />
              </FilterRow>
            </thead>
            <tbody className="divide-y divide-border">
              {changes.length === 0 ? <NoResultRow colSpan={3} /> : null}
              {pageItems.map((change) => {
                const applying = applyingId === change.gameId;
                return (
                  <tr className="transition-colors hover:bg-surface-2/40" key={change.gameId}>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-2">
                        <span className="font-semibold text-foreground">{change.gameName}</span>
                        {change.availabilityLocked ? (
                          <span className="inline-flex items-center gap-1 rounded border border-border bg-surface-2 px-2 py-0.5 text-xs font-semibold text-muted-foreground">
                            <Lock aria-hidden="true" className="size-3" />
                            Verrouillé
                          </span>
                        ) : null}
                      </div>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-2">
                        <AvailabilityBadge availability={change.currentAvailability} />
                        <ArrowRight aria-hidden="true" className="size-3 text-muted-foreground" />
                        <AvailabilityBadge availability={change.newAvailability} />
                      </div>
                    </td>
                    <td className="px-4 py-3 text-right">
                      <button
                        className="inline-flex min-h-8 items-center gap-1.5 rounded bg-accent px-3 text-xs font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-40"
                        disabled={change.availabilityLocked || applying}
                        type="button"
                        onClick={() => {
                          onApply(change.gameId, change.newAvailability);
                        }}
                      >
                        {applying ? <Loader2 aria-hidden="true" className="size-3.5 animate-spin" /> : null}
                        Appliquer
                      </button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
          <Pagination total={changes.length} page={page} pageSize={PAGE_SIZE} onPageChange={onPageChange} />
        </div>
      )}
    </TableCard>
  );
}

function IgnoredGamesTable({
  games,
  totalCount,
  filter,
  onFilterChange,
  ignoringName,
  onUnignore,
}: {
  games: IgnoredGame[];
  totalCount: number;
  filter: string;
  onFilterChange: (name: string) => void;
  ignoringName: string | null;
  onUnignore: (name: string) => void;
}) {
  return (
    <TableCard
      icon={<EyeOff aria-hidden="true" className="size-4 text-muted-foreground" />}
      title="Jeux ignorés"
      count={totalCount}
    >
      {totalCount === 0 ? (
        <p className="px-4 py-8 text-center text-sm text-muted-foreground">Aucun jeu ignoré.</p>
      ) : (
        <div className="min-w-0 overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border">
                <Th>Jeu</Th>
                <Th>Ignoré le</Th>
                <Th right>Action</Th>
              </tr>
              <FilterRow>
                <FilterText label="Filtrer par nom" value={filter} onChange={onFilterChange} />
                <td />
                <td />
              </FilterRow>
            </thead>
            <tbody className="divide-y divide-border">
              {games.length === 0 ? <NoResultRow colSpan={3} /> : null}
              {games.map((game) => (
                <tr className="transition-colors hover:bg-surface-2/40" key={game.name}>
                  <td className="px-4 py-3 text-foreground">{game.name}</td>
                  <td className="px-4 py-3 text-xs text-muted-foreground">
                    {new Intl.DateTimeFormat("fr-FR", { dateStyle: "short" }).format(new Date(game.ignoredAt))}
                  </td>
                  <td className="px-4 py-3 text-right">
                    <button
                      className="inline-flex min-h-7 items-center gap-1.5 rounded border border-border px-2.5 text-xs font-semibold text-muted-foreground transition-colors hover:border-accent hover:text-foreground disabled:cursor-not-allowed disabled:opacity-40"
                      disabled={ignoringName === game.name}
                      type="button"
                      onClick={() => onUnignore(game.name)}
                    >
                      {ignoringName === game.name ? (
                        <Loader2 aria-hidden="true" className="size-3 animate-spin" />
                      ) : null}
                      Retirer
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </TableCard>
  );
}

function RemovedFromSheetTable({
  games,
  totalCount,
  filter,
  onFilterChange,
}: {
  games: RemovedGame[];
  totalCount: number;
  filter: string;
  onFilterChange: (name: string) => void;
}) {
  return (
    <TableCard
      icon={<Tag aria-hidden="true" className="size-4 text-muted-foreground" />}
      title="Retirés du sheet"
      count={totalCount}
    >
      {totalCount === 0 ? (
        <p className="px-4 py-8 text-center text-sm text-muted-foreground">Aucun jeu retiré du sheet.</p>
      ) : (
        <div className="min-w-0 overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border">
                <Th>Jeu</Th>
                <Th right>Action</Th>
              </tr>
              <FilterRow>
                <FilterText label="Filtrer par nom" value={filter} onChange={onFilterChange} />
                <td />
              </FilterRow>
            </thead>
            <tbody className="divide-y divide-border">
              {games.length === 0 ? <NoResultRow colSpan={2} /> : null}
              {games.map((game) => (
                <tr className="transition-colors hover:bg-surface-2/40" key={game.gameId}>
                  <td className="px-4 py-3 text-foreground">{game.gameName}</td>
                  <td className="px-4 py-3 text-right">
                    <Link className="text-xs text-accent-text hover:underline" href={`/admin/jeux/${game.gameId}`}>
                      Voir
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </TableCard>
  );
}

// ── Column filters ────────────────────────────────────────────────────────────

const FILTER_INPUT_CLASS =
  "w-full min-w-0 rounded border border-border bg-surface px-2 py-1.5 text-xs font-normal normal-case tracking-normal text-foreground placeholder:text-muted-foreground focus:border-accent focus:outline-none";

function FilterRow({ children }: { children: React.ReactNode }) {
  return <tr className="border-b border-border bg-surface-2/30">{children}</tr>;
}

function FilterTextInput({ label, value, onChange }: { label: string; value: string; onChange: (v: string) => void }) {
  return (
    <input
      aria-label={label}
      className={`${FILTER_INPUT_CLASS} min-w-32`}
      placeholder="Filtrer…"
      type="search"
      value={value}
      onChange={(e) => onChange(e.target.value)}
    />
  );
}

function FilterSelectInput({
  label,
  allLabel = "Tous",
  options,
  value,
  onChange,
}: {
  label: string;
  allLabel?: string;
  options: { value: string; label: string }[];
  value: string;
  onChange: (v: string) => void;
}) {
  return (
    <select aria-label={label} className={FILTER_INPUT_CLASS} value={value} onChange={(e) => onChange(e.target.value)}>
      <option value="">{allLabel}</option>
      {options.map((o) => (
        <option key={o.value} value={o.value}>
          {o.label}
        </option>
      ))}
    </select>
  );
}

function FilterText(props: { label: string; value: string; onChange: (v: string) => void }) {
  return (
    <td className="px-4 py-2">
      <FilterTextInput {...props} />
    </td>
  );
}

function FilterSelect(props: {
  label: string;
  options: { value: string; label: string }[];
  value: string;
  onChange: (v: string) => void;
}) {
  return (
    <td className="px-4 py-2">
      <FilterSelectInput {...props} />
    </td>
  );
}

function NoResultRow({ colSpan }: { colSpan: number }) {
  return (
    <tr>
      <td className="px-4 py-8 text-center text-sm text-muted-foreground" colSpan={colSpan}>
        Aucun résultat pour ces filtres.
      </td>
    </tr>
  );
}

function asNewGamesFlag(v: string): NewGamesFilter["flag"] {
  return v === "adult" || v === "bundled" || v === "none" ? v : "";
}

// ── Shared primitives ─────────────────────────────────────────────────────────

function TableCard({
  icon,
  title,
  count,
  action,
  children,
}: {
  icon: React.ReactNode;
  title: string;
  count: number;
  action?: React.ReactNode;
  children: React.ReactNode;
}) {
  return (
    <div className="overflow-hidden rounded border border-border bg-surface">
      <div className="flex flex-wrap items-center gap-2 border-b border-border px-4 py-3">
        {icon}
        <h2 className="font-heading text-sm font-semibold text-foreground">
          {title}
          <span className="ml-2 font-mono text-xs font-normal text-muted-foreground">({count})</span>
        </h2>
        {action ? <div className="ml-auto">{action}</div> : null}
      </div>
      {children}
    </div>
  );
}

function Pagination({
  total,
  page,
  pageSize,
  onPageChange,
}: {
  total: number;
  page: number;
  pageSize: number;
  onPageChange: (p: number) => void;
}) {
  const totalPages = Math.ceil(total / pageSize);
  if (totalPages <= 1) return null;

  const from = (page - 1) * pageSize + 1;
  const to = Math.min(page * pageSize, total);

  const pages = buildPageRange(page, totalPages);

  return (
    <div className="flex items-center justify-between border-t border-border px-4 py-3">
      <span className="text-xs text-muted-foreground">
        {from}–{to} sur {total}
      </span>
      <div className="flex items-center gap-1">
        <button
          aria-label="Page précédente"
          className="inline-flex size-8 items-center justify-center rounded border border-border text-muted-foreground transition-colors hover:border-accent hover:text-foreground disabled:cursor-not-allowed disabled:opacity-40"
          disabled={page === 1}
          type="button"
          onClick={() => onPageChange(page - 1)}
        >
          <ChevronLeft aria-hidden="true" className="size-4" />
        </button>

        {pages.map((p, i) =>
          p === "…" ? (
            <span className="px-1 text-xs text-muted-foreground" key={`ellipsis-${i}`}>
              …
            </span>
          ) : (
            <button
              aria-current={p === page ? "page" : undefined}
              className={`inline-flex size-8 items-center justify-center rounded border text-xs font-medium transition-colors ${
                p === page
                  ? "border-accent bg-accent text-white"
                  : "border-border text-muted-foreground hover:border-accent hover:text-foreground"
              }`}
              key={p}
              type="button"
              onClick={() => onPageChange(p)}
            >
              {p}
            </button>
          ),
        )}

        <button
          aria-label="Page suivante"
          className="inline-flex size-8 items-center justify-center rounded border border-border text-muted-foreground transition-colors hover:border-accent hover:text-foreground disabled:cursor-not-allowed disabled:opacity-40"
          disabled={page === totalPages}
          type="button"
          onClick={() => onPageChange(page + 1)}
        >
          <ChevronRight aria-hidden="true" className="size-4" />
        </button>
      </div>
    </div>
  );
}

function buildPageRange(current: number, total: number): (number | "…")[] {
  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1);

  const result: (number | "…")[] = [];

  result.push(1);

  if (current > 3) result.push("…");

  const start = Math.max(2, current - 1);
  const end = Math.min(total - 1, current + 1);
  for (let p = start; p <= end; p++) result.push(p);

  if (current < total - 2) result.push("…");

  result.push(total);

  return result;
}

function Th({ children, right }: { children: React.ReactNode; right?: boolean }) {
  return (
    <th
      className={`px-4 py-2.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground ${right ? "text-right" : "text-left"}`}
    >
      {children}
    </th>
  );
}

function ThSortable({
  col,
  sort,
  onSort,
  children,
}: {
  col: "name" | "votes";
  sort: { col: "name" | "votes"; dir: "asc" | "desc" };
  onSort: (col: "name" | "votes") => void;
  children: React.ReactNode;
}) {
  const active = sort.col === col;
  return (
    <th className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">
      <button
        className={`inline-flex items-center gap-1 transition-colors hover:text-foreground ${active ? "text-foreground" : ""}`}
        type="button"
        onClick={() => onSort(col)}
      >
        {children}
        <ChevronsUpDown aria-hidden="true" className="size-3.5" />
      </button>
    </th>
  );
}

function AvailabilityBadge({ availability }: { availability: string }) {
  const label = availabilityLabel(availability);
  const cls =
    availability === "available"
      ? "border-success/50 bg-success/10 text-success"
      : availability === "experimental"
        ? "border-warning/50 bg-warning/10 text-warning"
        : "border-danger/50 bg-danger/10 text-danger";
  return (
    <span className={`inline-flex items-center rounded border px-2 py-0.5 text-xs font-semibold ${cls}`}>
      {label}
    </span>
  );
}

const UPDATE_STATUS_BADGE_CLASSES: Record<ApworldUpdateStatusTone, string> = {
  warning: "border-warning/50 bg-warning/10 text-warning",
  success: "border-success/50 bg-success/10 text-success",
  muted: "border-border bg-surface-2 text-muted-foreground",
  faint: "border-border bg-surface-2 text-muted-foreground/60",
};

function UpdateStatusBadge({ status }: { status: ApworldUpdate["updateStatus"] }) {
  return (
    <span className={`inline-flex items-center rounded border px-2 py-0.5 text-xs font-semibold ${UPDATE_STATUS_BADGE_CLASSES[updateStatusTone(status)]}`}>
      {updateStatusLabel(status)}
    </span>
  );
}

function LoadingSkeleton() {
  return (
    <div className="grid animate-pulse gap-6">
      {[1, 2, 3].map((i) => (
        <div className="overflow-hidden rounded border border-border bg-surface" key={i}>
          <div className="flex items-center gap-2 border-b border-border px-4 py-3">
            <div className="size-4 rounded bg-surface-2" />
            <div className="h-3.5 w-32 rounded bg-surface-2" />
          </div>
          <div className="space-y-px">
            {Array.from({ length: 3 }).map((_, j) => (
              <div className="h-12 bg-surface-2/60" key={j} />
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}

function ErrorPanel({ message, onRetry }: { message: string; onRetry: () => void }) {
  return (
    <div className="grid justify-items-center gap-3 rounded border border-danger/30 bg-surface p-8 text-center">
      <ShieldAlert aria-hidden="true" className="size-8 text-danger" />
      <h2 className="font-heading text-xl font-semibold text-foreground">Catalogue indisponible</h2>
      <p className="max-w-md text-sm leading-6 text-muted-foreground">{message}</p>
      <button
        className="inline-flex min-h-9 items-center gap-2 rounded border border-border px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
        type="button"
        onClick={onRetry}
      >
        <RefreshCw aria-hidden="true" className="size-4" />
        Réessayer
      </button>
    </div>
  );
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function availabilityLabel(availability: string): string {
  return (
    { available: "Disponible", experimental: "Expérimental", unavailable: "Indisponible" }[availability] ??
    availability
  );
}
