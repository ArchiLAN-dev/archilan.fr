import { Shovel } from "lucide-react";

const formatter = new Intl.NumberFormat("fr-FR");

/** Pluralised count: « 1 pelle », « 120 pelles ». */
export function pellesLabel(amount: number): string {
  return `${formatter.format(amount)} ${Math.abs(amount) > 1 ? "pelles" : "pelle"}`;
}

/**
 * An amount of pelles with the site's money mark (story 41.1). `signed` shows « +50 » / « -20 » for a
 * ledger line; otherwise a plain balance.
 */
export function PelleAmount({ amount, signed = false, className = "" }: { amount: number; signed?: boolean; className?: string }) {
  const text = signed && amount > 0 ? `+${formatter.format(amount)}` : formatter.format(amount);
  return (
    <span className={`inline-flex items-center gap-1 tabular-nums ${className}`}>
      <Shovel aria-hidden className="size-[1em] shrink-0" />
      <span>{text}</span>
      <span className="sr-only">{Math.abs(amount) > 1 ? "pelles" : "pelle"}</span>
    </span>
  );
}
