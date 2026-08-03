import type { SendQuality, TopItems } from "./build-item-content";

/**
 * Category colours, rebuilt for colour-vision deficiency.
 *
 * The first cut used accent (violet), `--color-chart-2`, danger (red), muted and border. Two of
 * those are the classic protan/deutan confusion pair, and `--color-chart-2` turned out to be a pure
 * grey (`oklch(0.556 0 0)`) - so three of the five categories were near-identical greys and the
 * two coloured ones were the pair hardest to tell apart. It was unreadable by construction.
 *
 * The three identity hues now come from the documented series palette and are validated: worst
 * adjacent pair Piège↔Utile at ΔE 13.7 under deuteranopia (target 8), 19.4 under normal vision.
 * Only ONE grey remains - a neutral is the right encoding for "nothing special", but two adjacent
 * greys are not - and "Non classé" is separated by **texture** rather than a second shade, the one
 * channel that survives every colour-vision deficiency.
 *
 * Colour is never the only channel here: segments are separated by a surface gap, and every count
 * is written out underneath the bar.
 */
const QUALITY_SEGMENTS = [
  { color: "var(--chart-series-1)", key: "progression", label: "Progression", striped: false },
  { color: "var(--chart-series-4)", key: "useful", label: "Utile", striped: false },
  { color: "var(--color-danger)", key: "trap", label: "Piège", striped: false },
  { color: "var(--color-muted-foreground)", key: "filler", label: "Remplissage", striped: false },
  { color: "var(--color-muted-foreground)", key: "unknown", label: "Non classé", striped: true },
] as const;

/** Diagonal hatching: the secondary encoding that distinguishes "Non classé" from "Remplissage". */
const STRIPES = "repeating-linear-gradient(45deg, currentColor 0 3px, transparent 3px 7px)";

/**
 * What circulated, and what it was worth (story 32.19).
 *
 * Rendered as plain CSS bars rather than a chart component: both readings are proportions of a
 * whole, which a div width expresses exactly, and it keeps the section a server component with no
 * client JavaScript at all.
 */
export function RecapItemContent({ topItems, quality }: { topItems: TopItems; quality: SendQuality[] }) {
  const maxCount = topItems.items[0]?.count ?? 0;
  const showQuality = quality.some((entry) => entry.total > entry.unknown);

  if (topItems.items.length === 0 && !showQuality) {
    return null;
  }

  return (
    <div className="grid gap-6 lg:grid-cols-2">
      {topItems.items.length > 0 ? (
        <div className="rounded-lg border border-border bg-surface p-4">
          <h3 className="font-heading text-lg font-bold text-foreground">Objets les plus échangés</h3>
          <p className="mt-1 text-xs text-muted-foreground">
            {topItems.items.length} objets affichés sur {topItems.distinctNames} distincts.
          </p>
          <ol className="mt-3 grid gap-2">
            {topItems.items.map((item) => (
              <li className="grid gap-1" key={item.name}>
                <div className="flex items-baseline justify-between gap-3 text-sm">
                  <span className="truncate text-foreground">{item.name}</span>
                  <span className="shrink-0 tabular-nums text-muted-foreground">{item.count}</span>
                </div>
                <div className="h-1.5 overflow-hidden rounded-full bg-border/40">
                  <div
                    className="h-full rounded-full"
                    style={{
                      backgroundColor: "var(--color-accent-text)",
                      width: `${maxCount === 0 ? 0 : (item.count / maxCount) * 100}%`,
                    }}
                  />
                </div>
              </li>
            ))}
          </ol>
        </div>
      ) : null}

      {showQuality ? (
        <div className="rounded-lg border border-border bg-surface p-4">
          <h3 className="font-heading text-lg font-bold text-foreground">Qualité des envois</h3>
          <p className="mt-1 text-xs text-muted-foreground">
            Ce que chaque joueur a envoyé aux autres. Cinquante objets de remplissage et cinquante
            déblocages font le même total, pas la même contribution.
          </p>

          <ul className="mt-3 grid gap-3">
            {quality.map((entry) => (
              <li className="grid gap-1" key={entry.slot}>
                <div className="flex items-baseline justify-between gap-3 text-sm">
                  <span className="truncate font-semibold text-foreground">{entry.name}</span>
                  <span className="shrink-0 tabular-nums text-muted-foreground">
                    {entry.progression} de progression sur {entry.total}
                  </span>
                </div>
                <div className="flex h-3 overflow-hidden rounded-full bg-border/40">
                  {QUALITY_SEGMENTS.filter((segment) => entry[segment.key] > 0).map((segment, index) => (
                    <div
                      className="h-full"
                      key={segment.key}
                      style={{
                        // A 2px surface gap between fills: the segment boundaries stay countable
                        // even when two hues converge for a colour-blind reader.
                        borderLeft: index === 0 ? undefined : "2px solid var(--color-surface)",
                        backgroundColor: segment.color,
                        backgroundImage: segment.striped ? STRIPES : undefined,
                        color: "var(--color-surface)",
                        width: `${(entry[segment.key] / entry.total) * 100}%`,
                      }}
                      title={`${segment.label} : ${entry[segment.key]}`}
                    />
                  ))}
                </div>

                {/* Every count in plain text: the bar shows the proportions, this reads without
                    relying on colour at all. */}
                <p className="flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
                  {QUALITY_SEGMENTS.filter((segment) => entry[segment.key] > 0).map((segment) => (
                    <span className="inline-flex items-center gap-1.5" key={segment.key}>
                      <span
                        aria-hidden="true"
                        className="size-2 shrink-0 rounded-full"
                        style={{
                          backgroundColor: segment.color,
                          backgroundImage: segment.striped ? STRIPES : undefined,
                          color: "var(--color-surface)",
                        }}
                      />
                      {entry[segment.key]} {segment.label.toLowerCase()}
                    </span>
                  ))}
                </p>
              </li>
            ))}
          </ul>

          <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
            {QUALITY_SEGMENTS.map((segment) => (
              <li className="inline-flex items-center gap-1.5" key={segment.key}>
                <span
                  aria-hidden="true"
                  className="size-2 rounded-full"
                  style={{
                    backgroundColor: segment.color,
                    backgroundImage: segment.striped ? STRIPES : undefined,
                    color: "var(--color-surface)",
                  }}
                />
                {segment.label}
              </li>
            ))}
          </ul>
        </div>
      ) : null}
    </div>
  );
}
