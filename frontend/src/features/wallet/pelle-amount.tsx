import type { ReactNode } from "react";
import { Shovel } from "lucide-react";

const formatter = new Intl.NumberFormat("fr-FR");

/** Gold, silver (`event`, the name the API keeps) or bronze. */
export type PelleTier = "gold" | "event" | "bronze";

/** Story 41.71: the one place that says which colour a pelle is. */
const TIER_TONE: Record<PelleTier, string> = {
  gold: "text-warning",
  event: "text-pelle-silver",
  bronze: "text-pelle-bronze",
};

/** Pluralised count: « 1 pelle », « 120 pelles ». */
export function pellesLabel(amount: number): string {
  return `${formatter.format(amount)} ${Math.abs(amount) > 1 ? "pelles" : "pelle"}`;
}

/**
 * An amount of pelles with the site's money mark (story 41.1). `signed` shows « +50 » / « -20 » for a
 * ledger line; otherwise a plain balance. Without a `kind`, it takes the colour of its context (story 41.71).
 */
export function PelleAmount({
  amount,
  signed = false,
  kind,
  className = "",
  mark,
}: {
  amount: number;
  signed?: boolean;
  kind?: PelleTier;
  className?: string;
  /** Story 41.71: stands in for the plain mark. */
  mark?: ReactNode;
}) {
  const text = signed && amount > 0 ? `+${formatter.format(amount)}` : formatter.format(amount);
  const tone = kind === undefined ? "" : TIER_TONE[kind];
  return (
    <span className={`inline-flex items-center gap-1 tabular-nums ${tone} ${className}`}>
      {mark ?? <Shovel aria-hidden className="size-[1em] shrink-0" />}
      <span>{text}</span>
      <span className="sr-only">{Math.abs(amount) > 1 ? "pelles" : "pelle"}</span>
    </span>
  );
}
