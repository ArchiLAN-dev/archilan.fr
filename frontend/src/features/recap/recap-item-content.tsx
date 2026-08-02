import type { SendQuality, TopItems } from "./build-item-content";

/**
 * Categories get a semantic palette of their own - highlight, neutral, danger, muted - deliberately
 * NOT the per-player series palette: on a page where a colour already means "this player", reusing
 * those hues for "this kind of item" would make the two readings collide.
 */
const QUALITY_SEGMENTS = [
  { color: "var(--color-accent-text)", key: "progression", label: "Progression" },
  { color: "var(--color-chart-2)", key: "useful", label: "Utile" },
  { color: "var(--color-danger)", key: "trap", label: "Piège" },
  { color: "var(--color-muted-foreground)", key: "filler", label: "Remplissage" },
  { color: "var(--color-border)", key: "unknown", label: "Non classé" },
] as const;

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
                <div className="flex h-2.5 overflow-hidden rounded-full bg-border/40">
                  {QUALITY_SEGMENTS.map((segment) => {
                    const value = entry[segment.key];
                    return value === 0 ? null : (
                      <div
                        key={segment.key}
                        style={{
                          backgroundColor: segment.color,
                          width: `${(value / entry.total) * 100}%`,
                        }}
                        title={`${segment.label} : ${value}`}
                      />
                    );
                  })}
                </div>
              </li>
            ))}
          </ul>

          <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
            {QUALITY_SEGMENTS.map((segment) => (
              <li className="inline-flex items-center gap-1.5" key={segment.key}>
                <span
                  aria-hidden="true"
                  className="size-2 rounded-full"
                  style={{ backgroundColor: segment.color }}
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
