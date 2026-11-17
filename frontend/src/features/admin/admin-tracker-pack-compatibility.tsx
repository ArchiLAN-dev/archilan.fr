import { CircleAlert, CircleCheck, CircleHelp, CircleX, FlaskConical } from "lucide-react";

import { ENGINE_VERSION } from "@/features/tracker/engine/engine-version";

import type { AdminTrackerPack, CompatibilityStatus, TrackerPackCompatibility } from "./admin-tracker-packs-api";

/**
 * Story 54.8: how the variants of a pack's served version fared when an admin tried them on the site, and the link to
 * try them. Reports come from the admin's browser (the tracker in trial mode sends one per variant loaded).
 */
export type PackCompatibilityState = CompatibilityStatus | "untried";

const LABELS: Record<PackCompatibilityState, string> = {
  ok: "Compatible",
  warnings: "Avec avertissements",
  failed: "Ne se charge pas",
  untried: "Pas encore essayé",
};

const TONES: Record<PackCompatibilityState, string> = {
  ok: "bg-success/15 text-success",
  warnings: "bg-warning/15 text-warning",
  failed: "bg-danger/15 text-danger",
  untried: "bg-muted text-muted-foreground",
};

const ICONS = { ok: CircleCheck, warnings: CircleAlert, failed: CircleX, untried: CircleHelp } as const;

/** The worst state among the variants; a variant never tried counts as « pas encore essayé » unless one failed or warned. */
export function packCompatibility(pack: Pick<AdminTrackerPack, "variants" | "compatibility">): PackCompatibilityState {
  const byVariant = new Map(pack.compatibility.map((report) => [report.variantUid, report.status]));
  const states = pack.variants.map((variant) => byVariant.get(variant.uid) ?? "untried");
  if (states.includes("failed")) return "failed";
  if (states.includes("warnings")) return "warnings";
  if (states.includes("untried") || states.length === 0) return "untried";
  return "ok";
}

/** Whether offering the pack needs an explicit confirmation: one of its variants does not load. */
export function hasFailedVariant(pack: Pick<AdminTrackerPack, "variants" | "compatibility">): boolean {
  const uids = new Set(pack.variants.map((variant) => variant.uid));
  return pack.compatibility.some((report) => report.status === "failed" && uids.has(report.variantUid));
}

export function trackerTrialHref(gameSlug: string, packId: string, versionId: string): string {
  const query = new URLSearchParams({ pack: packId, version: versionId, essai: "1" });
  return `/jeux/${encodeURIComponent(gameSlug)}/tracker?${query.toString()}`;
}

export function CompatibilityBadge({ state }: { state: PackCompatibilityState }) {
  const Icon = ICONS[state];
  return (
    <span className={`inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-semibold ${TONES[state]}`} data-compatibility={state}>
      <Icon aria-hidden="true" className="size-3.5" />
      {LABELS[state]}
    </span>
  );
}

function VariantReport({ name, report }: { name: string; report: TrackerPackCompatibility | undefined }) {
  const state: PackCompatibilityState = report?.status ?? "untried";
  return (
    <li className="grid gap-1">
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-sm font-semibold text-foreground">{name}</span>
        <CompatibilityBadge state={state} />
        {report !== undefined && report.engineVersion < ENGINE_VERSION ? (
          <span className="text-xs text-muted-foreground">essai antérieur au moteur actuel, à refaire</span>
        ) : null}
      </div>
      {report !== undefined && report.unsupportedApis.length > 0 ? (
        <p className="text-sm text-muted-foreground">
          Fonctions manquantes : <span className="font-mono text-foreground">{report.unsupportedApis.join(", ")}</span>
        </p>
      ) : null}
      {report !== undefined && report.errors.length > 0 ? (
        <ul className="grid gap-0.5 font-mono text-xs text-foreground">
          {report.errors.map((error, index) => (
            // Errors are a fixed list from one report: their position belongs to their identity.
            <li className="break-words" key={`${index}:${error.message}`}>
              {error.message}
              {error.file !== null ? ` (${error.file}${error.line !== null ? `:${error.line}` : ""})` : ""}
            </li>
          ))}
        </ul>
      ) : null}
    </li>
  );
}

type Props = { gameSlug: string; pack: AdminTrackerPack };

export function TrackerPackCompatibilityPanel({ gameSlug, pack }: Props) {
  const byVariant = new Map(pack.compatibility.map((report) => [report.variantUid, report]));
  return (
    <details className="grid gap-2 border-t border-border pt-3">
      <summary className="flex cursor-pointer flex-wrap items-center gap-2 text-sm font-semibold text-foreground">
        Compatibilité sur le site
        <CompatibilityBadge state={packCompatibility(pack)} />
      </summary>
      <ul className="mt-2 grid gap-3">
        {pack.variants.map((variant) => (
          <VariantReport key={variant.uid} name={variant.displayName} report={byVariant.get(variant.uid)} />
        ))}
      </ul>
      <a
        className="mt-2 inline-flex w-fit items-center gap-1 rounded border border-border px-3 py-1.5 text-sm font-semibold text-foreground transition-colors hover:border-accent"
        href={trackerTrialHref(gameSlug, pack.id, pack.versionId)}
        rel="noopener"
        target="_blank"
      >
        <FlaskConical aria-hidden="true" className="size-4" />
        Essayer
      </a>
      <p className="text-xs text-muted-foreground">
        L&apos;essai ouvre le tracker sur cette version, même si le pack n&apos;est pas proposé ; chaque variante chargée met à jour
        son état ici.
      </p>
    </details>
  );
}
