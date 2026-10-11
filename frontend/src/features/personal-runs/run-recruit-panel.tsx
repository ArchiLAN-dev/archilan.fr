"use client";

import { useState, type ReactNode } from "react";
import { ChevronDown, UsersRound } from "lucide-react";

import { InviteFriendsButton } from "./run-invitations";
import type { RunOpenness } from "./types";

/** What the folded card says about who can join (story 43.20). */
export function recruitSummary(openness: RunOpenness, seatsWanted: number | null): string {
  const seats = seatsWanted === null ? "" : ` · ${seatsWanted} place${seatsWanted > 1 ? "s" : ""}`;
  if (openness === "friends") return `Ouverte à tes amis${seats}`;
  if (openness === "members") return `Annonce en ligne${seats}`;
  return "Sur invitation";
}

/**
 * « Faire venir des joueurs » (story 43.20): one card for the owner with the invitation by name (43.1), who can join
 * on their own (43.14, 43.17, drafts only) and the invite link, instead of three blocks stacked without a hierarchy.
 * Folded by default: the invitation button and the current openness stay in sight, the settings and the link unfold.
 */
export function RunRecruitPanel({
  runId,
  participantIds,
  summary,
  openness,
  link,
}: {
  runId: string;
  participantIds: string[];
  summary: string;
  /** The openness setting, only on a draft run. */
  openness: ReactNode;
  link: ReactNode;
}) {
  const [open, setOpen] = useState(false);
  const bodyId = `recruit-body-${runId}`;

  return (
    <section aria-labelledby={`recruit-${runId}`} className="rounded-lg border border-border bg-surface">
      <div className="flex flex-wrap items-center justify-between gap-3 p-4">
        <button
          aria-controls={bodyId}
          aria-expanded={open}
          className="group flex min-w-0 flex-1 items-center gap-2 text-left"
          onClick={() => setOpen((v) => !v)}
          type="button"
        >
          <UsersRound aria-hidden className="size-4 shrink-0 text-accent-text" />
          <span className="grid min-w-0 gap-0.5">
            <span className="text-sm font-semibold text-foreground group-hover:text-accent-text" id={`recruit-${runId}`}>
              Faire venir des joueurs
            </span>
            <span className="truncate text-xs text-muted-foreground">
              {summary} · {open ? "masquer les réglages" : "réglages et lien d'invitation"}
            </span>
          </span>
          <ChevronDown
            aria-hidden
            className={`ml-1 size-4 shrink-0 text-muted-foreground transition-transform ${open ? "rotate-180" : ""}`}
          />
        </button>
        <InviteFriendsButton participantIds={participantIds} runId={runId} />
      </div>
      {open ? (
        <div className="grid gap-4 px-4 pb-4" id={bodyId}>
          {openness !== null && openness !== undefined ? <div className="border-t border-border pt-4">{openness}</div> : null}
          <div className="border-t border-border pt-4">{link}</div>
        </div>
      ) : null}
    </section>
  );
}
