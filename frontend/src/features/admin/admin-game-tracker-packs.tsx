"use client";

import { ExternalLink, LoaderCircle, Star } from "lucide-react";
import type { FormEvent } from "react";
import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import {
  fetchTrackerPacks,
  formatPackSize,
  importTrackerPack,
  trackerPacksQueryKey,
  updateTrackerPack,
  type AdminTrackerPack,
  type TrackerPackChanges,
} from "./admin-tracker-packs-api";
import { hasFailedVariant, TrackerPackCompatibilityPanel } from "./admin-tracker-pack-compatibility";
import { TrackerPackUpdates } from "./admin-tracker-pack-updates";

type Props = { gameId: string; gameSlug: string };

/**
 * Story 54.1: the PopTracker packs of a game. The admin pastes a GitHub link (a release, the repository or a zip),
 * the API downloads and checks the pack, and it joins the list. It stays hidden from players until enabled (54.2).
 */
export function AdminGameTrackerPacks({ gameId, gameSlug }: Props) {
  const queryClient = useQueryClient();
  const packsQuery = useQuery({
    queryKey: trackerPacksQueryKey(gameId),
    queryFn: () => fetchTrackerPacks(gameId),
    staleTime: DEFAULT_STALE_TIME,
  });
  const [url, setUrl] = useState("");
  const [importing, setImporting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const link = url.trim();
    if (link === "") return;
    setError(null);
    setSuccess(null);
    setImporting(true);
    try {
      const result = await importTrackerPack(gameId, link);
      if (!result.ok) {
        setError(result.message);
        return;
      }
      setUrl("");
      const version = result.packageVersion !== null ? ` v${result.packageVersion}` : "";
      setSuccess(
        result.hasApVariant
          ? `« ${result.name} »${version} est importé.`
          : `« ${result.name} »${version} est importé, sans autotracking Archipelago.`,
      );
      await queryClient.invalidateQueries({ queryKey: trackerPacksQueryKey(gameId) });
    } finally {
      setImporting(false);
    }
  }

  return (
    <section className="min-w-0 rounded-lg border border-border bg-surface p-6" aria-labelledby="tracker-packs-title">
      <h2 className="font-heading text-xl font-semibold text-foreground" id="tracker-packs-title">
        Trackers
      </h2>
      <p className="mt-1 text-sm text-muted-foreground">
        Packs PopTracker du jeu. Colle le lien GitHub d&apos;une release, du dépôt (dernière release) ou d&apos;un zip : le site
        télécharge le pack, le vérifie et le garde. Un pack importé reste caché tant qu’il n’est pas proposé aux joueurs.
      </p>

      <form className="mt-5 grid gap-3 sm:grid-cols-[1fr_auto]" onSubmit={submit}>
        <label className="sr-only" htmlFor="tracker-pack-url">
          Lien GitHub du pack
        </label>
        <input
          className="min-h-10 w-full rounded border border-border bg-background px-3 text-sm text-foreground outline-none focus:border-accent"
          disabled={importing}
          id="tracker-pack-url"
          inputMode="url"
          onChange={(e) => setUrl(e.target.value)}
          placeholder="https://github.com/auteur/pack/releases/tag/v1.0"
          type="url"
          value={url}
        />
        <button
          className="inline-flex min-h-10 items-center justify-center gap-2 rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
          disabled={importing || url.trim() === ""}
          type="submit"
        >
          {importing ? <LoaderCircle aria-hidden="true" className="size-4 animate-spin" /> : null}
          {importing ? "Import en cours…" : "Importer"}
        </button>
      </form>
      {importing ? (
        <p className="mt-2 text-sm text-muted-foreground" role="status">
          Téléchargement et vérification du pack, quelques secondes…
        </p>
      ) : null}
      {error !== null ? (
        <p className="mt-2 text-sm text-danger" role="alert">
          {error}
        </p>
      ) : null}
      {success !== null ? (
        <p className="mt-2 text-sm text-success" role="status">
          {success}
        </p>
      ) : null}

      <div className="mt-6">
        <TrackerPackList gameId={gameId} gameSlug={gameSlug} packs={packsQuery.data} loading={packsQuery.isPending} />
      </div>
    </section>
  );
}

export function TrackerPackList({
  gameId,
  gameSlug,
  packs,
  loading,
}: {
  gameId: string;
  gameSlug: string;
  packs: AdminTrackerPack[] | null | undefined;
  loading: boolean;
}) {
  if (loading) {
    return <p className="text-sm text-muted-foreground">Chargement des packs…</p>;
  }
  if (packs === null || packs === undefined) {
    return (
      <p className="text-sm text-danger" role="alert">
        Impossible de charger les packs de ce jeu.
      </p>
    );
  }
  if (packs.length === 0) {
    return <p className="text-sm text-muted-foreground">Aucun pack importé pour ce jeu.</p>;
  }

  return (
    <ul className="grid gap-3">
      {packs.map((pack) => (
        <TrackerPackRow gameId={gameId} gameSlug={gameSlug} key={pack.id} pack={pack} />
      ))}
    </ul>
  );
}

/**
 * One pack, with what decides how players are offered it (story 54.2): offered or not, default pack of the game,
 * variant opened first, and the licence an admin vouches for when none was detected.
 */
export function TrackerPackRow({ gameId, gameSlug, pack }: { gameId: string; gameSlug: string; pack: AdminTrackerPack }) {
  const queryClient = useQueryClient();
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  /** Story 54.8: offering a pack with a variant that does not load waits for an explicit yes. */
  const [confirmEnable, setConfirmEnable] = useState(false);
  const needsLicense = pack.licenseSpdx === null;
  const canEnable = !needsLicense || pack.licenseAcknowledged;

  async function change(changes: TrackerPackChanges) {
    setError(null);
    setSaving(true);
    try {
      const result = await updateTrackerPack(pack.id, changes);
      if (!result.ok) {
        setError(result.message);
        return;
      }
      await queryClient.invalidateQueries({ queryKey: trackerPacksQueryKey(gameId) });
    } finally {
      setSaving(false);
    }
  }

  return (
    <li className="grid gap-3 rounded border border-border bg-background p-4">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <p className="font-semibold text-foreground">
          {pack.name}
          {pack.packageVersion !== null ? <span className="ml-2 font-mono text-sm text-muted-foreground">v{pack.packageVersion}</span> : null}
        </p>
        <div className="flex flex-wrap gap-2">
          {pack.isDefault ? (
            <span className="inline-flex items-center gap-1 rounded bg-accent-warm/15 px-2 py-0.5 text-xs font-semibold text-accent-warm">
              <Star aria-hidden="true" className="size-3.5" />
              Par défaut
            </span>
          ) : null}
          <span
            className={
              pack.hasApVariant
                ? "rounded bg-accent/15 px-2 py-0.5 text-xs font-semibold text-accent-text"
                : "rounded bg-muted px-2 py-0.5 text-xs font-semibold text-muted-foreground"
            }
          >
            {pack.hasApVariant ? "Archipelago" : "Sans autotracking"}
          </span>
        </div>
      </div>
      {pack.author !== null ? <p className="text-sm text-muted-foreground">par {pack.author}</p> : null}
      <dl className="grid gap-1 text-sm sm:grid-cols-[auto_1fr] sm:gap-x-4">
        <dt className="text-muted-foreground">Variantes</dt>
        <dd className="text-foreground">{pack.variants.map((variant) => variant.displayName).join(", ")}</dd>
        <dt className="text-muted-foreground">Licence</dt>
        <dd className={needsLicense ? "text-accent-warm" : "text-foreground"}>
          {pack.licenseSpdx ?? "Non détectée, à vérifier avant de le proposer aux joueurs"}
        </dd>
        <dt className="text-muted-foreground">Taille</dt>
        <dd className="text-foreground">{formatPackSize(pack.sizeBytes)}</dd>
        {pack.minPopTrackerVersion !== null ? (
          <>
            <dt className="text-muted-foreground">PopTracker minimum</dt>
            <dd className="font-mono text-foreground">{pack.minPopTrackerVersion}</dd>
          </>
        ) : null}
      </dl>
      <a
        className="inline-flex w-fit items-center gap-1 text-sm text-accent-text underline-offset-2 hover:underline"
        href={pack.sourceUrl}
        rel="noopener noreferrer"
        target="_blank"
      >
        {pack.gitTag !== null ? `Source (${pack.gitTag})` : "Source"}
        <ExternalLink aria-hidden="true" className="size-3.5" />
      </a>

      <div className="grid gap-3 border-t border-border pt-3">
        {needsLicense ? (
          <label className="flex items-start gap-2 text-sm text-foreground">
            <input
              checked={pack.licenseAcknowledged}
              className="mt-0.5 size-4 accent-accent"
              disabled={saving}
              onChange={(e) => void change({ licenseAcknowledged: e.target.checked })}
              type="checkbox"
            />
            <span>La diffusion de ce pack est autorisée (vérifié auprès de son dépôt ou de son auteur).</span>
          </label>
        ) : null}
        <label className="flex items-center gap-2 text-sm font-semibold text-foreground">
          <input
            checked={pack.enabled}
            className="size-4 accent-accent"
            disabled={saving || (!pack.enabled && !canEnable)}
            onChange={(e) => {
              if (e.target.checked && hasFailedVariant(pack)) {
                setConfirmEnable(true);
                return;
              }
              void change({ enabled: e.target.checked });
            }}
            type="checkbox"
          />
          Proposé aux joueurs
        </label>
        {confirmEnable && !pack.enabled ? (
          <div className="grid gap-2 rounded border border-warning/50 bg-warning/10 p-3" role="alert">
            <p className="text-sm text-foreground">
              Une variante de ce pack ne se charge pas sur le site. Les joueurs qui l&apos;ouvriront verront une erreur.
            </p>
            <div className="flex flex-wrap gap-2">
              <button
                className="min-h-9 rounded bg-accent px-3 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
                disabled={saving}
                onClick={() => {
                  setConfirmEnable(false);
                  void change({ enabled: true });
                }}
                type="button"
              >
                Proposer quand même
              </button>
              <button
                className="min-h-9 rounded border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent"
                onClick={() => setConfirmEnable(false)}
                type="button"
              >
                Annuler
              </button>
            </div>
          </div>
        ) : null}
        <div className="flex flex-wrap items-center gap-3">
          <label className="text-sm text-foreground" htmlFor={`variant-${pack.id}`}>
            Variante ouverte par défaut
          </label>
          <select
            className="min-h-9 rounded border border-border bg-background px-2 text-sm text-foreground"
            disabled={saving}
            id={`variant-${pack.id}`}
            onChange={(e) => void change({ defaultVariantUid: e.target.value === "" ? null : e.target.value })}
            value={pack.defaultVariantUid ?? ""}
          >
            <option value="">La première ({pack.variants[0]?.displayName ?? "Standard"})</option>
            {pack.variants.map((variant) => (
              <option key={variant.uid} value={variant.uid}>
                {variant.displayName}
              </option>
            ))}
          </select>
          {pack.enabled && !pack.isDefault ? (
            <button
              className="inline-flex min-h-9 items-center gap-1 rounded border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:opacity-50"
              disabled={saving}
              onClick={() => void change({ isDefault: true })}
              type="button"
            >
              <Star aria-hidden="true" className="size-4" />
              Ouvrir ce pack par défaut
            </button>
          ) : null}
        </div>
        {error !== null ? (
          <p className="text-sm text-danger" role="alert">
            {error}
          </p>
        ) : null}
      </div>

      <TrackerPackCompatibilityPanel gameSlug={gameSlug} pack={pack} />
      <TrackerPackUpdates gameId={gameId} gameSlug={gameSlug} pack={pack} />
    </li>
  );
}
