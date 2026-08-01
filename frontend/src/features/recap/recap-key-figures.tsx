import type { KeyFigure } from "./build-key-figures";

/**
 * The headline numbers of a run (story 32.15), between the header and the analysis below.
 *
 * Presentational only: the list arrives already computed and already filtered - a figure that could
 * not be established is absent rather than shown as zero, so this component never has to decide
 * what "unknown" looks like.
 */
export function RecapKeyFigures({ figures }: { figures: KeyFigure[] }) {
  if (figures.length === 0) {
    return null;
  }

  return (
    <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
      {figures.map((figure) => (
        <div className="rounded-lg border border-border bg-surface px-4 py-3" key={figure.key}>
          <dt className="text-xs uppercase tracking-[0.12em] text-muted-foreground">{figure.label}</dt>
          <dd className="mt-1 font-heading text-2xl font-bold text-foreground">{figure.value}</dd>
        </div>
      ))}
    </dl>
  );
}
