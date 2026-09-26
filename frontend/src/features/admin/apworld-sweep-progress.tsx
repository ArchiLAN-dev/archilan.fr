import type { ApworldSweepProgressData } from "./admin-apworld-health-api";

/**
 * Story 38.9: how far the rolling test has come on the Archipelago image in use. Right after a new
 * image, the count starts low and grows a batch per night.
 */
export function ApworldSweepProgress({ progress }: { progress: ApworldSweepProgressData | null }) {
  if (progress === null) return null;

  const done = progress.total > 0 && progress.testedOnCurrentImage >= progress.total;
  const percent = progress.total > 0 ? Math.round((progress.testedOnCurrentImage / progress.total) * 100) : 0;

  return (
    <div className="grid gap-1.5 rounded border border-border bg-surface p-3 text-sm" role="status">
      <p className="text-foreground">
        Image en service : <span className="font-mono">{progress.currentImage}</span>
      </p>
      <p className="text-muted-foreground">
        {done
          ? `Test tournant : tout le catalogue (${progress.total} apworlds) a été testé sur cette image.`
          : `Test tournant : ${progress.testedOnCurrentImage} apworlds sur ${progress.total} testés sur cette image, un lot chaque nuit.`}
      </p>
      <div aria-hidden className="h-1.5 overflow-hidden rounded bg-border">
        <div className="h-full bg-accent" style={{ width: `${percent}%` }} />
      </div>
    </div>
  );
}
