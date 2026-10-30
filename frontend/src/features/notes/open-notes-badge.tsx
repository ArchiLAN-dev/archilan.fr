import { StickyNote } from "lucide-react";

import type { OpenNoteSummary } from "./admin-notes-api";
import { authorName } from "./admin-notes-panel";

/**
 * Story 46.3: a list row's open notes, made to catch the eye at check-in: a count badge, then the newest open note
 * itself, so it reads without a click. Nothing at all for a row without an open note.
 */
export function OpenNotesBadge({ summary }: { summary: OpenNoteSummary | undefined }) {
  if (summary === undefined) return null;
  const label = summary.count === 1 ? "1 note ouverte" : `${summary.count} notes ouvertes`;

  return (
    <div className="mt-1.5 grid max-w-xs gap-1">
      <span className="inline-flex w-fit items-center gap-1 rounded border border-accent-warm/50 bg-accent-warm/15 px-1.5 py-0.5 text-xs font-semibold text-accent-warm">
        <StickyNote aria-hidden="true" className="size-3" />
        {label}
      </span>
      <p className="line-clamp-2 whitespace-pre-wrap break-words text-xs font-normal text-foreground" title={summary.latest.body}>
        <span className="text-muted-foreground">{authorName(summary.latest.author)} : </span>
        {summary.latest.body}
      </p>
    </div>
  );
}
