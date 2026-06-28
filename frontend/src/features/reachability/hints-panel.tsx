"use client";

import { Lightbulb, MapPin, Package, User } from "lucide-react";
import { useState } from "react";

import type { HintEntry, HintsData } from "./types";
import { SETTABLE_HINT_STATUSES } from "./types";

const STATUS_STYLES: Record<string, { dot: string; label: string; badge: string }> = {
  found:       { dot: "bg-success",      label: "Trouvé",       badge: "border-success/30 bg-success/10 text-success" },
  priority:    { dot: "bg-accent-warm",  label: "Prioritaire",  badge: "border-accent-warm/30 bg-accent-warm/10 text-accent-warm" },
  avoid:       { dot: "bg-danger",       label: "Éviter",       badge: "border-danger/30 bg-danger/10 text-danger" },
  no_priority: { dot: "bg-border",       label: "Faible prio.", badge: "border-border bg-surface-2 text-muted-foreground" },
  unspecified: { dot: "bg-border",       label: "Non classé",   badge: "border-border bg-surface-2 text-muted-foreground" },
};

const FLAG_STYLES: Record<number, string> = {
  1: "text-accent-text",   // progression
  2: "text-accent-warm",   // useful
  4: "text-danger",        // trap
  0: "text-muted-foreground",
};

function itemFlagLabel(flags: number): string {
  if (flags & 4) return "Piège";
  if (flags & 1) return "Progression";
  if (flags & 2) return "Utile";
  return "Remplissage";
}

function itemFlagClass(flags: number): string {
  if (flags & 4) return FLAG_STYLES[4];
  if (flags & 1) return FLAG_STYLES[1];
  if (flags & 2) return FLAG_STYLES[2];
  return FLAG_STYLES[0];
}

function HintRow({
  hint,
  onSetStatus,
}: {
  hint: HintEntry;
  onSetStatus?: (locationId: number, status: number) => void;
}) {
  const status = STATUS_STYLES[hint.statusName] ?? STATUS_STYLES.unspecified;
  // A found hint is terminal; otherwise the owner may set its priority.
  const canEdit = onSetStatus !== undefined && !hint.found;

  return (
    <div className="card-glow grid gap-3 rounded border border-border bg-surface p-4 transition-colors hover:border-accent/40">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="flex items-center gap-2">
          <Package aria-hidden="true" className={`size-4 shrink-0 ${itemFlagClass(hint.itemFlags)}`} />
          <span className={`font-medium ${itemFlagClass(hint.itemFlags)}`}>
            {hint.itemName || `Item #${hint.itemId}`}
          </span>
          <span className={`text-xs ${itemFlagClass(hint.itemFlags)}`}>
            ({itemFlagLabel(hint.itemFlags)})
          </span>
        </div>
        {canEdit ? (
          <label className="inline-flex items-center gap-1.5">
            <span className="sr-only">Priorité de l&apos;indice</span>
            <span aria-hidden="true" className={`size-1.5 rounded-full ${status.dot}`} />
            <select
              className="rounded border border-border bg-surface-2 px-2 py-0.5 text-xs font-medium text-foreground outline-none focus:border-accent"
              onChange={(e) => onSetStatus(hint.locationId, Number(e.target.value))}
              value={hint.status}
            >
              {SETTABLE_HINT_STATUSES.map((s) => (
                <option key={s.value} value={s.value}>{s.label}</option>
              ))}
            </select>
          </label>
        ) : (
          <span className={`inline-flex items-center gap-1.5 rounded border px-2 py-0.5 text-xs font-medium ${status.badge}`}>
            <span aria-hidden="true" className={`size-1.5 rounded-full ${status.dot} ${hint.statusName === "found" ? "animate-pulse" : ""}`} />
            {status.label}
          </span>
        )}
      </div>

      <div className="grid gap-1.5 text-sm text-muted-foreground">
        <div className="flex items-center gap-2">
          <MapPin aria-hidden="true" className="size-3.5 shrink-0 text-accent-text" />
          <span className="font-mono text-xs text-foreground/80">
            {hint.locationName || `Location #${hint.locationId}`}
          </span>
        </div>
        {hint.findingPlayer !== hint.receivingPlayer ? (
          <div className="flex items-center gap-2">
            <User aria-hidden="true" className="size-3.5 shrink-0 text-accent-text" />
            <span className="text-xs">
              dans le monde de{" "}
              <span className="font-medium text-foreground/80">{hint.findingPlayerName}</span>
            </span>
          </div>
        ) : null}
        {hint.entrance ? (
          <div className="flex items-center gap-2">
            <span className="font-mono text-xs text-accent-text/70">via {hint.entrance}</span>
          </div>
        ) : null}
      </div>
    </div>
  );
}

export function HintsPanel({
  data,
  hintFree,
  onToggleFree,
  onSetStatus,
}: {
  data: HintsData;
  hintFree?: boolean;
  onToggleFree?: () => void;
  /** When provided, each pending hint shows a priority picker (AP hint status). */
  onSetStatus?: (locationId: number, status: number) => void;
}) {
  const [filter, setFilter] = useState<"all" | "pending" | "found">("all");

  const totalPoints = data.hintsUsed * data.hintCost + data.hintPointsAvailable;
  const budgetPct = totalPoints > 0 ? Math.round((data.hintPointsAvailable / totalPoints) * 100) : 100;

  const filtered = data.hints.filter((h) => {
    if (filter === "found") return h.found;
    if (filter === "pending") return !h.found;
    return true;
  });

  return (
    <div className="grid gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <Lightbulb aria-hidden="true" className="size-5 text-amber-400" />
          <h2 className="font-heading text-lg font-semibold text-foreground">
            Indices
          </h2>
          {data.hints.length > 0 ? (
            <span className="rounded border border-border bg-surface-2 px-2 py-0.5 text-xs text-muted-foreground">
              {data.hints.length}
            </span>
          ) : null}
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {onToggleFree !== undefined ? (
            <button
              className={`inline-flex items-center gap-1.5 rounded border px-3 py-1 text-xs font-medium transition-colors ${
                hintFree
                  ? "border-success/40 bg-success/10 text-success"
                  : "border-amber-500/30 bg-amber-500/5 text-amber-400"
              }`}
              onClick={onToggleFree}
              title={hintFree ? "Mode gratuit (admin) - cliquer pour passer en payant" : "Mode payant - cliquer pour passer en gratuit"}
              type="button"
            >
              <Lightbulb aria-hidden="true" className="size-3" />
              {hintFree ? "Gratuit (admin)" : `Payant · ${data.hintCost} pts`}
            </button>
          ) : null}

          {data.hints.length > 0 ? (
            <div className="flex items-center gap-1 rounded border border-border bg-surface p-1">
              {(["all", "pending", "found"] as const).map((f) => (
                <button
                  key={f}
                  className={`rounded px-3 py-1 text-xs font-medium transition-colors ${
                    filter === f
                      ? "bg-accent text-foreground"
                      : "text-muted-foreground hover:text-foreground"
                  }`}
                  onClick={() => { setFilter(f); }}
                  type="button"
                >
                  {f === "all" ? "Tous" : f === "pending" ? "En attente" : "Trouvés"}
                </button>
              ))}
            </div>
          ) : null}
        </div>
      </div>

      {/* Budget bar */}
      <div className="rounded border border-amber-500/20 bg-amber-500/5 p-4">
        <div className="mb-2 flex items-center justify-between text-xs">
          <span className="text-muted-foreground">
            <span className="font-medium text-foreground">{data.hintsUsed}</span> indice{data.hintsUsed > 1 ? "s" : ""} demandé{data.hintsUsed > 1 ? "s" : ""}
            {data.hintCost > 0 ? ` · ${data.hintCost} pts/indice` : ""}
          </span>
          <span className="font-medium text-amber-400">
            {data.hintPointsAvailable} pts disponibles
          </span>
        </div>
        <div className="h-1.5 overflow-hidden rounded-full bg-surface-2">
          <div
            className="h-full rounded-full bg-amber-500/60 transition-all duration-500"
            style={{ width: `${budgetPct}%` }}
          />
        </div>
        {data.hintCost > 0 && data.hintPointsAvailable >= data.hintCost ? (
          <p className="mt-1.5 text-xs text-muted-foreground">
            Prochain indice possible · encore{" "}
            <span className="text-amber-400">{Math.floor(data.hintPointsAvailable / data.hintCost)}</span>{" "}
            indice{Math.floor(data.hintPointsAvailable / data.hintCost) > 1 ? "s" : ""} possible{Math.floor(data.hintPointsAvailable / data.hintCost) > 1 ? "s" : ""}
          </p>
        ) : null}
      </div>

      {data.hints.length === 0 ? (
        <div className="rounded border border-border bg-surface p-6 text-center text-sm text-muted-foreground">
          Aucun indice demandé pour ce slot.
        </div>
      ) : filtered.length === 0 ? (
        <div className="rounded border border-border bg-surface p-4 text-center text-sm text-muted-foreground">
          Aucun indice dans cette catégorie.
        </div>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2">
          {filtered.map((hint) => (
            <HintRow hint={hint} key={`${hint.locationId}-${hint.itemId}`} onSetStatus={onSetStatus} />
          ))}
        </div>
      )}
    </div>
  );
}
