const ROW_KEYS = ["s1", "s2", "s3", "s4", "s5"] as const;

/**
 * Pulsing placeholder shown while a community tab loads - same look as the Jeux / Compte tabs
 * (account-registrations, membership-section) instead of a plain spinner + text.
 */
export function CommunityLoadingSkeleton({ rows = 3 }: { rows?: number }) {
  return (
    <div aria-hidden className="grid gap-3">
      {ROW_KEYS.slice(0, rows).map((key) => (
        <div className="h-20 animate-pulse rounded-lg border border-border bg-surface" key={key} />
      ))}
    </div>
  );
}
