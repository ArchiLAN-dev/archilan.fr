import { AlertTriangle } from "lucide-react";

/**
 * Story 38.7: the reasons a slot is to review, read defensively from a slot payload. A slot that holds
 * carries no `needsReview` field, which reads as nothing to review.
 */
export function parseNeedsReview(slot: unknown): string[] {
  if (typeof slot !== "object" || slot === null || !("needsReview" in slot) || !Array.isArray(slot.needsReview)) {
    return [];
  }
  return slot.needsReview.filter((reason): reason is string => typeof reason === "string");
}

/**
 * Story 38.7: the game of this slot switched apworld and the player's own YAML no longer holds. It was
 * kept as is (never replaced behind their back); the player fixes it, and the next save clears this.
 */
export function SlotNeedsReview({ reasons }: { reasons: string[] }) {
  if (reasons.length === 0) {
    return null;
  }
  return (
    <div className="mt-2 grid gap-1 rounded border border-warning/40 bg-warning/10 p-2 text-xs" role="status">
      <p className="flex items-center gap-1.5 font-semibold text-foreground">
        <AlertTriangle aria-hidden className="size-3.5 text-warning" />
        À revoir
        <span className="font-normal text-muted-foreground">Ce jeu a changé de version : ton YAML ne passe plus tel quel.</span>
      </p>
      <ul className="list-disc pl-5 text-muted-foreground">
        {reasons.map((reason) => (
          <li key={reason}>{reason}</li>
        ))}
      </ul>
    </div>
  );
}
