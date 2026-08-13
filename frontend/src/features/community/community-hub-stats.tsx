type Props = {
  memberCount: number | null;
  finishedSessions: number | null;
  checksDone: number | null;
  goalsReached: number | null;
};

/**
 * The hub's headline numbers (story 30.38).
 *
 * Deliberately a server component with no count-up: the homepage widget animates because it fetches on
 * the client, but this page exists for the logged-out visitor and the crawler, and an animated counter
 * ships "0" in the served HTML. A missing value renders "-" rather than dropping the tile, so the row
 * keeps its shape when one API call fails.
 */
export function CommunityHubStats({ memberCount, finishedSessions, checksDone, goalsReached }: Props) {
  const stats: { label: string; value: number | null }[] = [
    { label: "membres", value: memberCount },
    { label: "runs terminées", value: finishedSessions },
    { label: "checks complétés", value: checksDone },
    { label: "objectifs atteints", value: goalsReached },
  ];

  return (
    <div className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
      {stats.map((stat) => (
        <div className="rounded-lg border border-border bg-surface p-4 text-center sm:p-6" key={stat.label}>
          <p className="font-heading text-2xl font-bold text-foreground sm:text-4xl">
            {stat.value === null ? "-" : new Intl.NumberFormat("fr-FR").format(stat.value)}
          </p>
          <p className="mt-1 text-xs text-muted-foreground sm:mt-2 sm:text-sm">{stat.label}</p>
        </div>
      ))}
    </div>
  );
}
