"use client";

import { ArrowUpCircle, History, RefreshCw } from "lucide-react";
import type { FormEvent } from "react";
import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";

import {
  checkTrackerPackUpdate,
  formatPackSize,
  rollbackTrackerPack,
  trackerPacksQueryKey,
  upgradeTrackerPack,
  type AdminTrackerPack,
  type ServedVersionResult,
  type TrackerPackActionResult,
} from "./admin-tracker-packs-api";
import { trackerTrialHref } from "./admin-tracker-pack-compatibility";

const DATE_FORMAT = new Intl.DateTimeFormat("fr-FR", { dateStyle: "short", timeStyle: "short", timeZone: "Europe/Paris" });

/** Short codes of the last failed check, in words. */
const CHECK_ERRORS: Record<string, string> = {
  github_rate_limited: "GitHub limitait les requêtes",
  tracker_pack_download_failed: "GitHub ne répondait pas",
  tracker_pack_versions_file_invalid: "versions.json illisible",
  tracker_pack_url_invalid: "lien hors de GitHub",
};

type Props = { gameId: string; gameSlug: string; pack: AdminTrackerPack };

/**
 * Story 54.3: keeping a pack up to date. The nightly check notes a newer version; an admin reads what changes and
 * applies it, or installs the version at a GitHub link, or goes back to a version the pack kept. Story 54.13: on an
 * enabled pack, a new version is only kept; the admin tries it, then serves it.
 */
export function TrackerPackUpdates({ gameId, gameSlug, pack }: Props) {
  const queryClient = useQueryClient();
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [link, setLink] = useState("");
  const update = pack.availableUpdate;

  async function run(action: string, call: () => Promise<TrackerPackActionResult | ServedVersionResult>) {
    setError(null);
    setNotice(null);
    setBusy(action);
    try {
      const result = await call();
      if (!result.ok) {
        setError(result.message);
        return;
      }
      if ("defaultVariantReset" in result && !result.served) {
        const name = result.packageVersion !== null ? `La version ${result.packageVersion}` : "La version";
        setNotice(`${name} est installée mais pas encore proposée aux joueurs : essaie-la, puis « Servir cette version ».`);
      } else if ("defaultVariantReset" in result) {
        const served = result.packageVersion !== null ? `La version ${result.packageVersion} est proposée aux joueurs.` : "Version changée.";
        setNotice(result.defaultVariantReset ? `${served} La variante par défaut n'existe plus dans cette version : la première est ouverte.` : served);
      }
      await queryClient.invalidateQueries({ queryKey: trackerPacksQueryKey(gameId) });
    } finally {
      setBusy(null);
    }
  }

  function submitLink(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const url = link.trim();
    if (url === "") return;
    void run("link", () => upgradeTrackerPack(pack.id, url)).then(() => setLink(""));
  }

  return (
    <div className="grid gap-3 border-t border-border pt-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm font-semibold text-foreground">Mises à jour</p>
        <button
          className="inline-flex min-h-9 items-center gap-1 rounded border border-border px-3 text-sm text-foreground transition-colors hover:border-accent disabled:opacity-50"
          disabled={busy !== null}
          onClick={() => void run("check", () => checkTrackerPackUpdate(pack.id))}
          type="button"
        >
          <RefreshCw aria-hidden="true" className={busy === "check" ? "size-4 animate-spin" : "size-4"} />
          Vérifier maintenant
        </button>
      </div>
      <p className="text-xs text-muted-foreground">
        {pack.lastCheckedAt !== null ? `Dernière vérification : ${DATE_FORMAT.format(new Date(pack.lastCheckedAt))}` : "Jamais vérifié"}
        {pack.lastCheckError !== null ? (
          <span className="text-accent-warm"> · échec ({CHECK_ERRORS[pack.lastCheckError] ?? pack.lastCheckError})</span>
        ) : null}
      </p>

      {update !== null ? (
        <div className="grid gap-2 rounded border border-accent/40 bg-accent/5 p-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <span className="inline-flex items-center gap-1 rounded bg-accent/15 px-2 py-0.5 text-xs font-semibold text-accent-text">
              <ArrowUpCircle aria-hidden="true" className="size-3.5" />v{update.packageVersion} disponible
            </span>
            <button
              className="inline-flex min-h-9 items-center rounded bg-accent px-3 text-sm font-semibold text-white hover:bg-accent-hover disabled:opacity-50"
              disabled={busy !== null}
              onClick={() => void run("upgrade", () => upgradeTrackerPack(pack.id))}
              type="button"
            >
              {busy === "upgrade" ? "Mise à jour…" : "Mettre à jour"}
            </button>
          </div>
          {update.changelog.length > 0 ? (
            <details className="text-sm">
              <summary className="cursor-pointer text-accent-text">Ce qui change</summary>
              <div className="mt-2 grid max-h-64 gap-1 overflow-y-auto whitespace-pre-wrap text-muted-foreground">
                {update.changelog.map((line, index) => (
                  // Lines can repeat and never move: line number plus text is a stable key.
                  <p key={`${index}-${line}`}>{line}</p>
                ))}
              </div>
            </details>
          ) : null}
        </div>
      ) : null}

      <form className="flex flex-wrap gap-2" onSubmit={submitLink}>
        <label className="sr-only" htmlFor={`update-link-${pack.id}`}>
          Lien GitHub d&apos;une version
        </label>
        <input
          className="min-h-9 min-w-0 flex-1 rounded border border-border bg-background px-2 text-sm text-foreground"
          disabled={busy !== null}
          id={`update-link-${pack.id}`}
          onChange={(e) => setLink(e.target.value)}
          placeholder="Lien GitHub d'une autre version (release, tag ou zip)"
          type="url"
          value={link}
        />
        <button
          className="inline-flex min-h-9 items-center rounded border border-border px-3 text-sm text-foreground transition-colors hover:border-accent disabled:opacity-50"
          disabled={busy !== null || link.trim() === ""}
          type="submit"
        >
          {busy === "link" ? "Installation…" : "Installer ce lien"}
        </button>
      </form>

      {pack.versions.length > 1 ? (
        <div className="grid gap-1">
          <p className="inline-flex items-center gap-1 text-xs font-semibold text-muted-foreground">
            <History aria-hidden="true" className="size-3.5" />
            Versions gardées
          </p>
          <ul className="grid gap-1 text-sm">
            {pack.versions.map((version) => (
              <li className="flex flex-wrap items-center justify-between gap-2" key={version.id}>
                <span className="text-foreground">
                  <span className="font-mono">{version.packageVersion !== null ? `v${version.packageVersion}` : (version.gitTag ?? "sans version")}</span>
                  <span className="text-muted-foreground">
                    {" "}
                    · {DATE_FORMAT.format(new Date(version.importedAt))} · {formatPackSize(version.sizeBytes)}
                  </span>
                </span>
                {version.current ? (
                  <span className="text-xs font-semibold text-success">En service</span>
                ) : (
                  <span className="flex flex-wrap items-center gap-3">
                    <a
                      className="text-xs text-accent-text underline-offset-2 hover:underline"
                      href={trackerTrialHref(gameSlug, pack.id, version.id)}
                      rel="noopener"
                      target="_blank"
                    >
                      Essayer
                    </a>
                    <button
                      className="text-xs text-accent-text underline-offset-2 hover:underline disabled:opacity-50"
                      disabled={busy !== null}
                      onClick={() => void run(`rollback-${version.id}`, () => rollbackTrackerPack(pack.id, version.id))}
                      type="button"
                    >
                      Servir cette version
                    </button>
                  </span>
                )}
              </li>
            ))}
          </ul>
        </div>
      ) : null}

      {notice !== null ? <p className="text-sm text-success">{notice}</p> : null}
      {error !== null ? (
        <p className="text-sm text-danger" role="alert">
          {error}
        </p>
      ) : null}
    </div>
  );
}
