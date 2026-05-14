"use client";

import {
  AlertTriangle,
  ArrowRight,
  CheckCircle2,
  ChevronDown,
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
  Search,
  ShieldAlert,
  Shuffle,
  Tag,
  ThumbsUp,
} from "lucide-react";
import Link from "next/link";
import { useEffect, useState } from "react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

// ── Types ────────────────────────────────────────────────────────────────────

type CatalogLink = { label: string; url: string | null };

type NewGame = {
  name: string;
  availability: string;
  bundledWithAp: boolean;
  adultContent: boolean;
  links: CatalogLink[];
  votes: number;
};

type StabilityChange = {
  gameId: string;
  gameName: string;
  currentAvailability: string;
  newAvailability: string;
  availabilityLocked: boolean;
};

type RemovedGame = {
  gameId: string;
  gameName: string;
};

type ApworldUpdate = {
  gameId: string;
  gameName: string;
  deployedVersion: string | null;
  latestVersion: string | null;
  releaseUrl: string | null;
  publishedAt: string | null;
  updateStatus: "update_available" | "up_to_date" | "unknown" | "not_tracked";
};

type IgnoredGame = {
  name: string;
  ignoredAt: string;
};

type CatalogSyncData = {
  cachedAt: string | null;
  googleApiAvailable: boolean;
  githubChecksAvailable: boolean;
  newGames: NewGame[];
  ignoredGames: IgnoredGame[];
  stabilityChanged: StabilityChange[];
  removedFromSheet: RemovedGame[];
  apworldUpdates: ApworldUpdate[];
};

type PageState =
  | { kind: "loading" }
  | { kind: "ready"; data: CatalogSyncData }
  | { kind: "error"; message: string };

type AdminGameBase = {
  name: string;
  slug: string;
  description: string;
  coverImageUrl: string | null;
  coverImageAlt: string;
  coverImageCredit: string;
};

const PAGE_SIZE = 20;

// ── Data fetcher ─────────────────────────────────────────────────────────────

async function fetchCatalogSync(
  force = false,
): Promise<{ ok: true; data: CatalogSyncData } | { ok: false; message: string }> {
  const url = `${env.apiBaseUrl}/admin/catalog-sync${force ? "?force=true" : ""}`;
  try {
    const res = await apiFetch(url);
    if (!res.ok) {
      if (res.status === 503) {
        const body = (await res.json().catch(() => ({}))) as { error?: { message?: string } };
        return { ok: false, message: body.error?.message ?? "Le catalogue Google Sheets est injoignable." };
      }
      return { ok: false, message: "Impossible de charger la synchronisation catalogue." };
    }
    return { ok: true, data: (await res.json()) as CatalogSyncData };
  } catch {
    return { ok: false, message: "Impossible de contacter l'API." };
  }
}

async function fetchGameRequestVotes(): Promise<Map<string, number>> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/game-requests`);
    if (!res.ok) return new Map();
    const json = (await res.json()) as { data?: { normalizedName: string; voteCount: number }[] };
    const map = new Map<string, number>();
    for (const item of json.data ?? []) {
      map.set(item.normalizedName, item.voteCount);
    }
    return map;
  } catch {
    return new Map();
  }
}

async function reloadAll(
  force = false,
): Promise<{ kind: "ready"; data: CatalogSyncData } | { kind: "error"; message: string }> {
  const [syncResult, votesMap] = await Promise.all([fetchCatalogSync(force), fetchGameRequestVotes()]);
  if (!syncResult.ok) return { kind: "error", message: syncResult.message };
  return {
    kind: "ready",
    data: {
      ...syncResult.data,
      newGames: syncResult.data.newGames.map((g) => ({
        ...g,
        votes: votesMap.get(g.name.toLowerCase().trim()) ?? 0,
      })),
    },
  };
}

// ── Main component ────────────────────────────────────────────────────────────

export function AdminCatalogueSyncPage() {
  const [state, setState] = useState<PageState>({ kind: "loading" });
  const [syncing, setSyncing] = useState(false);
  const [checkingUpdates, setCheckingUpdates] = useState(false);
  const [applyingId, setApplyingId] = useState<string | null>(null);
  const [ignoringName, setIgnoringName] = useState<string | null>(null);
  const [flash, setFlash] = useState<{ kind: "success" | "error"; text: string } | null>(null);
  const [search, setSearch] = useState("");
  const [newGamesPage, setNewGamesPage] = useState(1);
  const [updatesPage, setUpdatesPage] = useState(1);
  const [changesPage, setChangesPage] = useState(1);

  function handleSearch(q: string) {
    setSearch(q);
    setNewGamesPage(1);
    setUpdatesPage(1);
    setChangesPage(1);
  }

  useEffect(() => {
    let cancelled = false;
    async function init() {
      const next = await reloadAll();
      if (!cancelled) setState(next);
    }
    void init();
    return () => { cancelled = true; };
  }, []);

  async function handleSync(): Promise<void> {
    setSyncing(true);
    try {
      setState(await reloadAll(true));
    } finally {
      setSyncing(false);
    }
  }

  async function handleCheckUpdates(): Promise<void> {
    setCheckingUpdates(true);
    setFlash(null);
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/admin/catalog-sync/check-updates`, { method: "POST" });
      if (!res.ok) {
        setFlash({ kind: "error", text: "La vérification des mises à jour a échoué." });
        return;
      }
      const body = await res.json() as { checked?: number; rateLimitHit?: boolean };
      const extra = body.rateLimitHit ? " (limite GitHub atteinte)" : "";
      setFlash({ kind: "success", text: `${body.checked ?? 0} jeu(x) vérifié(s)${extra}.` });
      setState(await reloadAll());
    } catch {
      setFlash({ kind: "error", text: "Impossible de contacter l'API." });
    } finally {
      setCheckingUpdates(false);
    }
  }

  async function handleApply(gameId: string, newAvailability: string): Promise<void> {
    setApplyingId(gameId);
    setFlash(null);
    try {
      const gameRes = await apiFetch(`${env.apiBaseUrl}/admin/games/${gameId}`);
      if (!gameRes.ok) {
        setFlash({ kind: "error", text: "Impossible de récupérer les données du jeu." });
        return;
      }
      const { data: game } = await gameRes.json() as { data: AdminGameBase };

      const patchRes = await apiFetch(`${env.apiBaseUrl}/admin/games/${gameId}`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          name: game.name,
          slug: game.slug,
          description: game.description,
          coverImageUrl: game.coverImageUrl,
          coverImageAlt: game.coverImageAlt,
          coverImageCredit: game.coverImageCredit,
          availability: newAvailability,
        }),
      });

      if (!patchRes.ok) {
        setFlash({ kind: "error", text: "Impossible d'appliquer le changement de disponibilité." });
        return;
      }

      setFlash({ kind: "success", text: `Disponibilité mise à jour : ${availabilityLabel(newAvailability)}.` });
      setState(await reloadAll());
    } catch {
      setFlash({ kind: "error", text: "Impossible de contacter l'API." });
    } finally {
      setApplyingId(null);
    }
  }

  async function handleIgnore(name: string): Promise<void> {
    setIgnoringName(name);
    setFlash(null);
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/admin/catalog-sync/ignored`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ name }),
      });
      if (!res.ok) {
        setFlash({ kind: "error", text: "Impossible d'ignorer ce jeu." });
        return;
      }
      setState(await reloadAll());
    } catch {
      setFlash({ kind: "error", text: "Impossible de contacter l'API." });
    } finally {
      setIgnoringName(null);
    }
  }

  async function handleUnignore(name: string): Promise<void> {
    setIgnoringName(name);
    setFlash(null);
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/admin/catalog-sync/ignored/${encodeURIComponent(name)}`, {
        method: "DELETE",
      });
      if (!res.ok) {
        setFlash({ kind: "error", text: "Impossible de retirer l'état ignoré." });
        return;
      }
      setState(await reloadAll());
    } catch {
      setFlash({ kind: "error", text: "Impossible de contacter l'API." });
    } finally {
      setIgnoringName(null);
    }
  }

  const data = state.kind === "ready" ? state.data : null;
  const q = search.toLowerCase().trim();
  const filteredNewGames = data?.newGames.filter((g) => !q || g.name.toLowerCase().includes(q)) ?? [];
  const allTrackedUpdates = data?.apworldUpdates.filter((u) => u.updateStatus !== "not_tracked") ?? [];
  const filteredTrackedUpdates = allTrackedUpdates.filter((u) => !q || u.gameName.toLowerCase().includes(q));
  const filteredChanges = data?.stabilityChanged.filter((c) => !q || c.gameName.toLowerCase().includes(q)) ?? [];

  return (
    <section className="grid w-full gap-8 px-4 py-10">
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
              disabled={syncing || state.kind === "loading"}
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

      {state.kind === "loading" ? (
        <LoadingSkeleton />
      ) : state.kind === "error" ? (
        <ErrorPanel message={state.message} onRetry={() => void handleSync()} />
      ) : (
        <>
          <div className="relative">
            <Search aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <input
              className="w-full rounded border border-border bg-surface py-2.5 pl-9 pr-4 text-sm text-foreground placeholder:text-muted-foreground focus:border-accent focus:outline-none"
              placeholder="Filtrer par nom de jeu…"
              type="search"
              value={search}
              onChange={(e) => handleSearch(e.target.value)}
            />
          </div>

          <div className="grid gap-6">
            <NewGamesTable
              games={filteredNewGames}
              totalCount={data!.newGames.length}
              page={newGamesPage}
              onPageChange={setNewGamesPage}
              ignoringName={ignoringName}
              onIgnore={(name) => void handleIgnore(name)}
            />
            <IgnoredGamesSection
              games={data!.ignoredGames}
              ignoringName={ignoringName}
              onUnignore={(name) => void handleUnignore(name)}
            />
            <ApworldUpdatesTable
              updates={filteredTrackedUpdates}
              totalCount={allTrackedUpdates.length}
              githubChecksAvailable={data!.githubChecksAvailable}
              checkingUpdates={checkingUpdates}
              onCheckUpdates={() => void handleCheckUpdates()}
              page={updatesPage}
              onPageChange={setUpdatesPage}
            />
            <StabilityChangedTable
              changes={filteredChanges}
              totalCount={data!.stabilityChanged.length}
              applyingId={applyingId}
              onApply={(id, av) => void handleApply(id, av)}
              page={changesPage}
              onPageChange={setChangesPage}
            />
            <RemovedFromSheetSection games={data!.removedFromSheet} />
          </div>
        </>
      )}
    </section>
  );
}

// ── Sections (table-based) ────────────────────────────────────────────────────

function NewGamesTable({
  games,
  totalCount,
  page,
  onPageChange,
  ignoringName,
  onIgnore,
}: {
  games: NewGame[];
  totalCount: number;
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
      ) : games.length === 0 ? (
        <p className="px-4 py-8 text-center text-sm text-muted-foreground">Aucun résultat pour cette recherche.</p>
      ) : (
        <div className="overflow-x-auto">
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
            </thead>
            <tbody className="divide-y divide-border">
              {pageItems.map((game, i) => (
                <tr className="transition-colors hover:bg-surface-2/40" key={i}>
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
  githubChecksAvailable,
  checkingUpdates,
  onCheckUpdates,
  page,
  onPageChange,
}: {
  updates: ApworldUpdate[];
  totalCount: number;
  githubChecksAvailable: boolean;
  checkingUpdates: boolean;
  onCheckUpdates: () => void;
  page: number;
  onPageChange: (p: number) => void;
}) {
  if (totalCount === 0) return null;

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
      {updates.length === 0 ? (
        <p className="px-4 py-8 text-center text-sm text-muted-foreground">Aucun résultat pour cette recherche.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border">
                <Th>Jeu</Th>
                <Th>Statut</Th>
                <Th>Versions</Th>
                <Th>Publiée le</Th>
                <Th right>Release</Th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
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
                    ) : (
                      <span className="text-xs text-muted-foreground/40">-</span>
                    )}
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
  applyingId,
  onApply,
  page,
  onPageChange,
}: {
  changes: StabilityChange[];
  totalCount: number;
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
      ) : changes.length === 0 ? (
        <p className="px-4 py-8 text-center text-sm text-muted-foreground">Aucun résultat pour cette recherche.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border">
                <Th>Jeu</Th>
                <Th>Changement</Th>
                <Th right>Action</Th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
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

function IgnoredGamesSection({
  games,
  ignoringName,
  onUnignore,
}: {
  games: IgnoredGame[];
  ignoringName: string | null;
  onUnignore: (name: string) => void;
}) {
  if (games.length === 0) return null;

  return (
    <details className="group overflow-hidden rounded border border-border bg-surface">
      <summary className="flex cursor-pointer list-none items-center gap-2 px-4 py-3 text-sm font-semibold text-foreground">
        <EyeOff aria-hidden="true" className="size-4 text-muted-foreground" />
        <span>Jeux ignorés</span>
        <span className="ml-1 font-mono text-xs font-normal text-muted-foreground">({games.length})</span>
        <ChevronDown
          aria-hidden="true"
          className="ml-auto size-4 text-muted-foreground transition-transform group-open:rotate-180"
        />
      </summary>
      <ul className="divide-y divide-border border-t border-border">
        {games.map((game) => (
          <li className="flex items-center gap-3 px-4 py-2.5" key={game.name}>
            <span className="text-sm text-muted-foreground">{game.name}</span>
            <span className="text-xs text-muted-foreground/50">
              ignoré le{" "}
              {new Intl.DateTimeFormat("fr-FR", { dateStyle: "short" }).format(new Date(game.ignoredAt))}
            </span>
            <button
              className="ml-auto inline-flex min-h-7 items-center gap-1.5 rounded border border-border px-2.5 text-xs font-semibold text-muted-foreground transition-colors hover:border-accent hover:text-foreground disabled:cursor-not-allowed disabled:opacity-40"
              disabled={ignoringName === game.name}
              type="button"
              onClick={() => onUnignore(game.name)}
            >
              {ignoringName === game.name ? (
                <Loader2 aria-hidden="true" className="size-3 animate-spin" />
              ) : null}
              Retirer
            </button>
          </li>
        ))}
      </ul>
    </details>
  );
}

function RemovedFromSheetSection({ games }: { games: RemovedGame[] }) {
  if (games.length === 0) return null;

  return (
    <details className="group overflow-hidden rounded border border-border bg-surface">
      <summary className="flex cursor-pointer list-none items-center gap-2 px-4 py-3 text-sm font-semibold text-foreground">
        <Tag aria-hidden="true" className="size-4 text-muted-foreground" />
        <span>Retirés du sheet</span>
        <span className="ml-1 font-mono text-xs font-normal text-muted-foreground">({games.length})</span>
        <ChevronDown
          aria-hidden="true"
          className="ml-auto size-4 text-muted-foreground transition-transform group-open:rotate-180"
        />
      </summary>
      <ul className="divide-y divide-border border-t border-border">
        {games.map((game) => (
          <li className="flex items-center gap-3 px-4 py-2.5" key={game.gameId}>
            <span className="text-sm text-muted-foreground">{game.gameName}</span>
            <Link
              className="ml-auto text-xs text-accent-text hover:underline"
              href={`/admin/jeux/${game.gameId}`}
            >
              Voir
            </Link>
          </li>
        ))}
      </ul>
    </details>
  );
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

function UpdateStatusBadge({ status }: { status: ApworldUpdate["updateStatus"] }) {
  if (status === "update_available") {
    return (
      <span className="inline-flex items-center rounded border border-warning/50 bg-warning/10 px-2 py-0.5 text-xs font-semibold text-warning">
        Mise à jour disponible
      </span>
    );
  }
  if (status === "up_to_date") {
    return (
      <span className="inline-flex items-center rounded border border-success/50 bg-success/10 px-2 py-0.5 text-xs font-semibold text-success">
        À jour
      </span>
    );
  }
  if (status === "unknown") {
    return (
      <span className="inline-flex items-center rounded border border-border bg-surface-2 px-2 py-0.5 text-xs font-semibold text-muted-foreground">
        Non vérifié
      </span>
    );
  }
  return (
    <span className="inline-flex items-center rounded border border-border bg-surface-2 px-2 py-0.5 text-xs font-semibold text-muted-foreground/60">
      Non suivi
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
