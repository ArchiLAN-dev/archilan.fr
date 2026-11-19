"use client";

import Link from "next/link";
import { ExternalLink, LoaderCircle, PackagePlus } from "lucide-react";
import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import {
  catalogTrackerPacksQueryKey,
  fetchCatalogTrackerPacks,
  fetchGamesToEquip,
  gamesToEquipQueryKey,
  type CatalogTrackerPack,
} from "./admin-catalog-tracker-packs-api";
import { importTrackerPack, trackerPacksQueryKey } from "./admin-tracker-packs-api";

/**
 * Story 54.12: the PopTracker packs the community catalog names. Proposed only: a pack is imported by the admin's
 * click, through the usual import, and stays hidden from players until enabled.
 */

type SuggestionsProps = {
  gameId: string;
  /** True while an import of the game runs: the buttons wait. */
  busy: boolean;
  onImport: (url: string) => void;
};

/** The catalog's packs for one game, in its « Trackers » section. Nothing at all when there are none. */
export function CatalogTrackerPackSuggestions({ gameId, busy, onImport }: SuggestionsProps) {
  const query = useQuery({
    queryKey: catalogTrackerPacksQueryKey(gameId),
    queryFn: () => fetchCatalogTrackerPacks(gameId),
    staleTime: DEFAULT_STALE_TIME,
  });
  const suggestions = query.data ?? [];
  if (suggestions.length === 0) return null;

  return (
    <div className="mt-5 rounded border border-accent/40 bg-accent/5 p-4">
      <h3 className="text-sm font-semibold text-foreground">Pack recommandé par le catalogue</h3>
      <p className="mt-1 text-xs text-muted-foreground">
        Le catalogue communautaire des jeux Archipelago indique ce pack pour ce jeu.
      </p>
      <ul className="mt-3 grid gap-2">
        {suggestions.map((pack) => (
          <SuggestionRow busy={busy} key={pack.url} onImport={onImport} pack={pack} />
        ))}
      </ul>
    </div>
  );
}

function SuggestionRow({ pack, busy, onImport }: { pack: CatalogTrackerPack; busy: boolean; onImport: (url: string) => void }) {
  const importUrl = pack.importUrl;
  return (
    <li className="flex flex-wrap items-center justify-between gap-3">
      <a
        className="inline-flex min-w-0 items-center gap-1.5 break-all text-sm text-accent-text hover:underline"
        href={pack.url}
        rel="noreferrer"
        target="_blank"
      >
        {pack.repository ?? pack.label}
        <ExternalLink aria-hidden="true" className="size-3.5 shrink-0" />
      </a>
      {importUrl !== null ? (
        <button
          className="inline-flex min-h-9 items-center gap-2 rounded bg-accent px-3 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
          disabled={busy}
          onClick={() => onImport(importUrl)}
          type="button"
        >
          <PackagePlus aria-hidden="true" className="size-4" />
          Importer
        </button>
      ) : (
        <span className="text-xs text-muted-foreground">Hors GitHub : à récupérer à la main</span>
      )}
    </li>
  );
}

type ImportState = { kind: "pending" } | { kind: "done"; message: string } | { kind: "failed"; message: string };

/** The library's games the catalog names a pack for, to equip one after the other. Nothing when there are none. */
export function GamesToEquipPanel() {
  const queryClient = useQueryClient();
  const query = useQuery({ queryKey: gamesToEquipQueryKey, queryFn: fetchGamesToEquip, staleTime: DEFAULT_STALE_TIME });
  const [open, setOpen] = useState(false);
  const [imports, setImports] = useState<Record<string, ImportState>>({});
  const games = query.data ?? [];
  if (games.length === 0 && Object.keys(imports).length === 0) return null;

  async function importFor(gameId: string, url: string) {
    setImports((prev) => ({ ...prev, [gameId]: { kind: "pending" } }));
    const result = await importTrackerPack(gameId, url);
    setImports((prev) => ({
      ...prev,
      [gameId]: result.ok ? { kind: "done", message: `« ${result.name} » importé, à activer dans la fiche du jeu.` } : { kind: "failed", message: result.message },
    }));
    if (result.ok) {
      await queryClient.invalidateQueries({ queryKey: trackerPacksQueryKey(gameId) });
      await queryClient.invalidateQueries({ queryKey: catalogTrackerPacksQueryKey(gameId) });
    }
  }

  return (
    <section className="rounded border border-border bg-surface p-4" aria-labelledby="games-to-equip-title">
      <button
        aria-expanded={open}
        className="flex w-full items-center justify-between gap-3 text-left"
        onClick={() => setOpen((v) => !v)}
        type="button"
      >
        <span className="font-heading text-base font-semibold text-foreground" id="games-to-equip-title">
          Trackers proposés par le catalogue ({games.length})
        </span>
        <span className="text-sm text-accent-text">{open ? "Masquer" : "Afficher"}</span>
      </button>
      {open ? (
        <ul className="mt-4 grid gap-3">
          {games.map((game) => {
            const pack = game.suggestions[0];
            const state = imports[game.gameId];
            if (pack === undefined || pack.importUrl === null) return null;
            const importUrl = pack.importUrl;
            return (
              <li className="grid gap-1 border-t border-border pt-3 sm:grid-cols-[1fr_auto] sm:items-center" key={game.gameId}>
                <div className="min-w-0">
                  <Link className="text-sm font-medium text-foreground hover:text-accent-text" href={`/admin/jeux/${game.gameId}`}>
                    {game.gameName}
                  </Link>
                  <a className="block break-all text-xs text-muted-foreground hover:underline" href={pack.url} rel="noreferrer" target="_blank">
                    {pack.repository}
                  </a>
                  {state?.kind === "done" ? <p className="text-xs text-success" role="status">{state.message}</p> : null}
                  {state?.kind === "failed" ? <p className="text-xs text-danger" role="alert">{state.message}</p> : null}
                </div>
                {state?.kind === "done" ? null : (
                  <button
                    className="inline-flex min-h-9 items-center justify-center gap-2 rounded bg-accent px-3 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
                    disabled={state?.kind === "pending"}
                    onClick={() => void importFor(game.gameId, importUrl)}
                    type="button"
                  >
                    {state?.kind === "pending" ? <LoaderCircle aria-hidden="true" className="size-4 animate-spin" /> : <PackagePlus aria-hidden="true" className="size-4" />}
                    Importer
                  </button>
                )}
              </li>
            );
          })}
        </ul>
      ) : null}
    </section>
  );
}
