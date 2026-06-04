"use client";

import { ChevronLeft, ChevronRight, Gamepad2, Pencil, Plus, ShieldAlert, Trash2 } from "lucide-react";
import Link from "next/link";
import { createPortal } from "react-dom";
import { useRef, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import {
  fetchAdminGames,
  type AdminGame,
  type AdminGameListFilters,
} from "./admin-game-library-api";

const DEFAULT_FILTERS: AdminGameListFilters = {
  page: 1,
  perPage: 50,
  search: "",
  availability: "",
  yamlReady: "",
};

type FlashMessage = { kind: "success" | "error"; text: string };

export function AdminGameLibraryDashboard() {
  const queryClient = useQueryClient();

  const [filters, setFilters] = useState<AdminGameListFilters>(DEFAULT_FILTERS);
  const [draftSearch, setDraftSearch] = useState("");
  const [draftAvailability, setDraftAvailability] = useState<AdminGameListFilters["availability"]>("");
  const [draftYamlReady, setDraftYamlReady] = useState<AdminGameListFilters["yamlReady"]>("");
  const [flash, setFlash] = useState<FlashMessage | null>(null);

  const { data, isLoading, isError } = useQuery({
    queryKey: ["admin-games", filters],
    queryFn: () => fetchAdminGames(filters),
    staleTime: DEFAULT_STALE_TIME,
  });

  const games = data?.data ?? [];
  const total = data?.meta.total ?? 0;
  const totalPages = data?.meta.totalPages ?? 1;
  const currentPage = filters.page;

  function applyFilters() {
    setFilters({
      page: 1,
      perPage: filters.perPage,
      search: draftSearch,
      availability: draftAvailability,
      yamlReady: draftYamlReady,
    });
  }

  function resetFilters() {
    setDraftSearch("");
    setDraftAvailability("");
    setDraftYamlReady("");
    setFilters(DEFAULT_FILTERS);
  }

  function goToPage(page: number) {
    setFilters((prev) => ({ ...prev, page }));
  }

  async function deleteGame(game: AdminGame) {
    if (!window.confirm(`Supprimer « ${game.name} » de la bibliothèque ?`)) return;

    const response = await apiFetch(`${env.apiBaseUrl}/admin/games/${game.id}`, {
      method: "DELETE",
    });

    if (!response.ok) {
      setFlash({ kind: "error", text: "Suppression impossible : le jeu est peut-être déjà utilisé." });
      return;
    }

    await queryClient.invalidateQueries({ queryKey: ["admin-games"] });
    setFlash({ kind: "success", text: "Jeu supprimé." });
  }

  const hasActiveFilters = filters.search !== "" || filters.availability !== "" || filters.yamlReady !== "";

  return (
    <section className="grid w-full gap-8 px-4 py-10">
      <header>
        <p className="mb-3 text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">Backoffice</p>
        <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
          <div>
            <h1 className="font-heading text-4xl font-bold leading-tight text-foreground">
              Bibliothèque de jeux
            </h1>
            <p className="mt-3 max-w-2xl text-muted-foreground">
              Maintiens les jeux Archipelago disponibles pour les futures configurations d&apos;événements.
            </p>
          </div>
          <Link
            className="inline-flex min-h-11 items-center justify-center gap-2 rounded bg-accent px-5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
            href="/admin/jeux/nouveau"
          >
            <Plus aria-hidden="true" className="size-4" />
            Nouveau jeu
          </Link>
        </div>
      </header>

      {flash ? (
        <p
          className={`border p-3 text-sm ${flash.kind === "success" ? "border-success/50 text-success" : "border-danger/50 text-danger"}`}
          role={flash.kind === "success" ? "status" : "alert"}
        >
          {flash.text}
        </p>
      ) : null}

      <div className="flex flex-wrap gap-3">
        <input
          className="min-h-10 flex-1 rounded border border-border bg-background px-3 text-sm outline-none focus:border-accent"
          placeholder="Rechercher par nom ou slug…"
          type="search"
          value={draftSearch}
          onChange={(e) => setDraftSearch(e.target.value)}
          onKeyDown={(e) => { if (e.key === "Enter") applyFilters(); }}
        />
        <select
          className="min-h-10 rounded border border-border bg-background px-3 text-sm text-foreground outline-none focus:border-accent"
          value={draftAvailability}
          onChange={(e) => setDraftAvailability(e.target.value as AdminGameListFilters["availability"])}
        >
          <option value="">Toutes disponibilités</option>
          <option value="available">Disponible</option>
          <option value="experimental">Expérimental</option>
          <option value="unavailable">Indisponible</option>
        </select>
        <select
          className="min-h-10 rounded border border-border bg-background px-3 text-sm text-foreground outline-none focus:border-accent"
          value={draftYamlReady}
          onChange={(e) => setDraftYamlReady(e.target.value as AdminGameListFilters["yamlReady"])}
        >
          <option value="">Tout statut YAML</option>
          <option value="1">YAML configuré</option>
          <option value="0">YAML manquant</option>
        </select>
        <button
          className="min-h-10 rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
          type="button"
          onClick={applyFilters}
        >
          Rechercher
        </button>
        {hasActiveFilters ? (
          <button
            className="min-h-10 rounded border border-border px-4 text-sm text-muted-foreground transition-colors hover:border-accent"
            type="button"
            onClick={resetFilters}
          >
            Réinitialiser
          </button>
        ) : null}
      </div>

      {!isLoading && !isError ? (
        <p className="text-xs text-muted-foreground">
          {total} jeu{total !== 1 ? "x" : ""}
          {hasActiveFilters ? " (filtrés)" : ""}
        </p>
      ) : null}

      <GameTable
        games={games}
        isLoading={isLoading}
        isError={isError}
        onDelete={deleteGame}
      />

      {!isLoading && !isError && totalPages > 1 ? (
        <Pagination currentPage={currentPage} totalPages={totalPages} onPageChange={goToPage} />
      ) : null}
    </section>
  );
}

function GameTable({
  games,
  isLoading,
  isError,
  onDelete,
}: {
  games: AdminGame[];
  isLoading: boolean;
  isError: boolean;
  onDelete: (game: AdminGame) => void;
}) {
  if (isError) {
    return (
      <div className="grid justify-items-center gap-3 border border-border bg-surface p-8 text-center">
        <ShieldAlert aria-hidden="true" className="size-8 text-danger" />
        <h2 className="font-heading text-2xl font-semibold text-foreground">Jeux indisponibles</h2>
        <p className="max-w-md text-sm leading-6 text-muted-foreground">
          Impossible de charger la bibliothèque. Vérifiez vos droits admin.
        </p>
      </div>
    );
  }

  return (
    <div className="overflow-x-auto border border-border bg-surface">
      <table className="w-full min-w-[600px] border-collapse text-left text-sm">
        <thead className="border-b border-border text-muted-foreground">
          <tr>
            <th className="px-4 py-3 font-medium">Jeu</th>
            <th className="px-4 py-3 font-medium">Disponibilité</th>
            <th className="px-4 py-3 font-medium">YAML</th>
            <th className="px-4 py-3 font-medium">Utilisations</th>
            <th className="px-4 py-3 font-medium">Actions</th>
          </tr>
        </thead>
        <tbody>
          {isLoading
            ? Array.from({ length: 5 }).map((_, i) => (
                <tr className="animate-pulse border-b border-border last:border-b-0" key={i}>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-3">
                      <div className="h-10 w-7 shrink-0 rounded bg-surface-2" />
                      <div className="flex flex-col gap-1.5">
                        <div className="h-3.5 rounded bg-surface-2" style={{ width: `${[120, 96, 140, 108, 88][i]}px` }} />
                        <div className="h-2.5 w-20 rounded bg-surface-2 opacity-50" />
                      </div>
                    </div>
                  </td>
                  <td className="px-4 py-3"><div className="h-3 w-20 rounded bg-surface-2" /></td>
                  <td className="px-4 py-3"><div className="h-5 w-14 rounded bg-surface-2" /></td>
                  <td className="px-4 py-3"><div className="h-3 w-6 rounded bg-surface-2" /></td>
                  <td className="px-4 py-3">
                    <div className="flex gap-2">
                      <div className="h-9 w-24 rounded bg-surface-2" />
                      <div className="h-9 w-20 rounded bg-surface-2" />
                    </div>
                  </td>
                </tr>
              ))
            : games.length === 0
              ? (
                  <tr>
                    <td className="px-4 py-12 text-center text-muted-foreground" colSpan={5}>
                      <div className="flex flex-col items-center gap-2">
                        <Gamepad2 aria-hidden="true" className="size-8 text-accent-text" />
                        <span>Aucun jeu trouvé. Ajuste les filtres ou ajoute le premier jeu Archipelago.</span>
                      </div>
                    </td>
                  </tr>
                )
              : games.map((game) => (
                  <tr className="border-b border-border last:border-b-0" key={game.id}>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-3">
                        {game.coverImageUrl ? (
                          <ThumbnailPreview src={game.coverImageUrl} />
                        ) : (
                          <div className="flex h-10 w-7 shrink-0 items-center justify-center rounded bg-surface-2">
                            <Gamepad2 aria-hidden="true" className="size-4 text-muted-foreground" />
                          </div>
                        )}
                        <div>
                          <p className="font-semibold text-foreground">{game.name}</p>
                          <p className="font-mono text-xs text-muted-foreground">{game.slug}</p>
                        </div>
                      </div>
                    </td>
                    <td className="px-4 py-4 text-muted-foreground">{availabilityLabel(game.availability)}</td>
                    <td className="px-4 py-3">
                      {game.isYamlReady ? (
                        <span className="inline-flex items-center rounded border border-success/50 bg-success/10 px-2 py-0.5 text-xs font-semibold text-success">
                          Prêt
                        </span>
                      ) : (
                        <span className="inline-flex items-center rounded border border-warning/50 bg-warning/10 px-2 py-0.5 text-xs font-semibold text-warning">
                          Manquant
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-4 text-muted-foreground">{game.usageCount}</td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap gap-2">
                        <Link
                          className="inline-flex min-h-9 items-center justify-center gap-1.5 rounded border border-border px-3 text-xs font-semibold text-foreground transition-colors hover:border-accent"
                          href={`/admin/jeux/${game.id}`}
                        >
                          <Pencil aria-hidden="true" className="size-3" />
                          Configurer
                        </Link>
                        <button
                          className="inline-flex min-h-9 items-center justify-center gap-1.5 rounded border border-border px-3 text-xs font-semibold text-foreground transition-colors hover:border-danger"
                          type="button"
                          onClick={() => onDelete(game)}
                        >
                          <Trash2 aria-hidden="true" className="size-3" />
                          Supprimer
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
        </tbody>
      </table>
    </div>
  );
}

function Pagination({
  currentPage,
  totalPages,
  onPageChange,
}: {
  currentPage: number;
  totalPages: number;
  onPageChange: (page: number) => void;
}) {
  const pages = buildPageRange(currentPage, totalPages);

  return (
    <nav aria-label="Pagination" className="flex items-center justify-center gap-1">
      <button
        aria-label="Page précédente"
        className="inline-flex min-h-9 min-w-9 items-center justify-center rounded border border-border text-sm text-foreground transition-colors hover:border-accent disabled:opacity-40 disabled:pointer-events-none"
        disabled={currentPage <= 1}
        type="button"
        onClick={() => onPageChange(currentPage - 1)}
      >
        <ChevronLeft aria-hidden="true" className="size-4" />
      </button>

      {pages.map((entry, i) =>
        entry === "…" ? (
          <span className="px-1 text-muted-foreground" key={`ellipsis-${i}`}>…</span>
        ) : (
          <button
            aria-current={entry === currentPage ? "page" : undefined}
            className={`inline-flex min-h-9 min-w-9 items-center justify-center rounded border text-sm transition-colors ${
              entry === currentPage
                ? "border-accent bg-accent text-white"
                : "border-border text-foreground hover:border-accent"
            }`}
            key={entry}
            type="button"
            onClick={() => onPageChange(entry)}
          >
            {entry}
          </button>
        ),
      )}

      <button
        aria-label="Page suivante"
        className="inline-flex min-h-9 min-w-9 items-center justify-center rounded border border-border text-sm text-foreground transition-colors hover:border-accent disabled:opacity-40 disabled:pointer-events-none"
        disabled={currentPage >= totalPages}
        type="button"
        onClick={() => onPageChange(currentPage + 1)}
      >
        <ChevronRight aria-hidden="true" className="size-4" />
      </button>
    </nav>
  );
}

function buildPageRange(current: number, total: number): (number | "…")[] {
  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1);

  const result: (number | "…")[] = [1];

  if (current > 3) result.push("…");

  const start = Math.max(2, current - 1);
  const end = Math.min(total - 1, current + 1);
  for (let i = start; i <= end; i++) result.push(i);

  if (current < total - 2) result.push("…");
  result.push(total);

  return result;
}

export function ThumbnailPreview({ src }: { src: string }) {
  const ref = useRef<HTMLDivElement>(null);
  const [style, setStyle] = useState<React.CSSProperties | null>(null);

  function show() {
    if (!ref.current) return;
    const rect = ref.current.getBoundingClientRect();
    const previewW = 192;
    const left =
      rect.right + 12 + previewW > window.innerWidth
        ? rect.left - previewW - 12
        : rect.right + 12;
    setStyle({
      position: "fixed",
      top: rect.top + rect.height / 2,
      left,
      transform: "translateY(-50%)",
      zIndex: 9999,
      pointerEvents: "none",
    });
  }

  return (
    <div ref={ref} className="inline-block shrink-0" onMouseEnter={show} onMouseLeave={() => setStyle(null)}>
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        alt=""
        className="h-10 w-7 cursor-zoom-in rounded object-cover transition-opacity hover:opacity-80"
        loading="lazy"
        src={src}
      />
      {style
        ? createPortal(
            <div aria-hidden="true" style={style}>
              <div className="overflow-hidden rounded-xl border border-border shadow-[0_16px_48px_rgba(0,0,0,0.7)]" style={{ animation: "thumbnail-pop 120ms ease-out both" }}>
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img alt="" className="block h-64 w-auto object-cover" src={src} />
              </div>
            </div>,
            document.body,
          )
        : null}
    </div>
  );
}

function availabilityLabel(availability: AdminGame["availability"]) {
  return {
    available: "Disponible",
    experimental: "Expérimental",
    unavailable: "Indisponible",
  }[availability];
}
