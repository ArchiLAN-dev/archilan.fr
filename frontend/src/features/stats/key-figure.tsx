import type { ReactNode } from "react";

const numberFormatter = new Intl.NumberFormat("fr-FR");

/**
 * How a total compares with the period before (story 42.1 AC4): « +3 (+25 %) », « -2 (-40 %) », « = », or
 * « nouveau » when there was nothing to compare with.
 */
export function deltaText(total: number, previous: number): string {
  const diff = total - previous;
  if (previous === 0) return total === 0 ? "=" : "nouveau";
  if (diff === 0) return "=";
  const sign = diff > 0 ? "+" : "-";
  const percent = Math.round((Math.abs(diff) / previous) * 100);
  return `${sign}${numberFormatter.format(Math.abs(diff))} (${sign}${percent} %)`;
}

type Props = {
  label: string;
  value: ReactNode;
  /** Absent for a count of the moment, which has no previous period. */
  trend?: { total: number; previous: number };
};

/** A headline number of a section, with its change against the period before. */
export function KeyFigure({ label, value, trend }: Props) {
  const tone =
    trend === undefined || trend.total === trend.previous
      ? "text-muted-foreground"
      : trend.total > trend.previous
        ? "text-success"
        : "text-danger";

  return (
    <div className="rounded-xl border border-border p-4">
      <dt className="text-sm text-muted-foreground">{label}</dt>
      <dd className="mt-1 font-heading text-2xl font-bold text-foreground">{value}</dd>
      {trend !== undefined ? (
        <dd className={`mt-0.5 text-xs ${tone}`}>
          {deltaText(trend.total, trend.previous)} <span className="text-muted-foreground">vs période précédente</span>
        </dd>
      ) : (
        <dd className="mt-0.5 text-xs text-muted-foreground">aujourd&apos;hui</dd>
      )}
    </div>
  );
}

export function formatCount(value: number): string {
  return numberFormatter.format(value);
}
