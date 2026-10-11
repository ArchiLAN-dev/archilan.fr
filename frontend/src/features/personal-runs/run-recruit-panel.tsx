"use client";

import { useState, type ReactNode } from "react";
import Link from "next/link";
import { ArrowRight, CalendarClock, ChevronDown, Lock, Megaphone, Users, UsersRound } from "lucide-react";

import { plannedLabel } from "./run-listings";
import { InviteFriendsButton } from "./run-invitations";
import type { RunOpenness } from "./types";

/** What the card says about who can join (story 43.20). */
export function recruitSummary(openness: RunOpenness, seatsWanted: number | null): string {
  const seats = seatsWanted === null ? "" : ` · ${seatsWanted} place${seatsWanted > 1 ? "s" : ""}`;
  if (openness === "friends") return `Ouverte à tes amis${seats}`;
  if (openness === "members") return `Annonce en ligne${seats}`;
  return "Sur invitation";
}

/** Who can join the run right now, as saved (story 43.22). */
export type RecruitState = {
  openness: RunOpenness;
  seatsWanted: number | null;
  pitch: string | null;
  plannedFor: string | null;
};

const PILL: Record<RunOpenness, { className: string; icon: typeof Lock }> = {
  invite: { className: "border-border text-muted-foreground", icon: Lock },
  friends: { className: "border-accent/60 bg-accent/15 text-accent-text", icon: Users },
  members: { className: "border-success/50 bg-success/10 text-success", icon: Megaphone },
};

/** Story 43.22: the state as a pill beside the title rather than a line mixed with the toggle. */
export function RecruitPill({ state }: { state: Pick<RecruitState, "openness" | "seatsWanted"> }) {
  const { className, icon: Icon } = PILL[state.openness];
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-semibold ${className}`}>
      {state.openness === "members" ? (
        <span aria-hidden className="size-1.5 animate-pulse rounded-full bg-success" />
      ) : (
        <Icon aria-hidden className="size-3.5" />
      )}
      {recruitSummary(state.openness, state.seatsWanted)}
    </span>
  );
}

/** Story 43.22: the listing as members see it, while the card is folded. */
function ListingPreview({ pitch, plannedFor }: { pitch: string; plannedFor: string | null }) {
  const planned = plannedLabel(plannedFor);
  return (
    <div className="grid gap-1.5 rounded-lg border border-border bg-background/40 px-3 py-2.5">
      <p className="line-clamp-2 whitespace-pre-line text-sm text-foreground">{pitch}</p>
      <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
        {planned !== null ? (
          <span className="inline-flex items-center gap-1.5">
            <CalendarClock aria-hidden className="size-3.5" />
            {planned}
          </span>
        ) : null}
        <Link className="ml-auto inline-flex items-center gap-1 font-medium text-accent-text hover:text-accent-text-hover" href="/communaute/parties">
          Voir dans les annonces
          <ArrowRight aria-hidden className="size-3.5" />
        </Link>
      </div>
    </div>
  );
}

/**
 * « Faire venir des joueurs » (story 43.20): one card for the owner with the invitation by name (43.1), who can join
 * on their own (43.14, 43.17, drafts only) and the invite link. Folded by default. Story 43.22: the state is a pill,
 * the settings open from their own button, and a listing online shows as members read it.
 */
export function RunRecruitPanel({
  runId,
  participantIds,
  state,
  openness,
  link,
}: {
  runId: string;
  participantIds: string[];
  state: RecruitState;
  /** The openness setting, only on a draft run. */
  openness: ReactNode;
  link: ReactNode;
}) {
  const [open, setOpen] = useState(false);
  const bodyId = `recruit-body-${runId}`;
  const listed = state.openness === "members" && state.pitch !== null && state.pitch !== "";

  return (
    <section aria-labelledby={`recruit-${runId}`} className="grid grid-cols-1 gap-3 rounded-lg border border-border bg-surface p-4">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
        <UsersRound aria-hidden className="size-4 shrink-0 text-accent-text" />
        <h2 className="text-sm font-semibold text-foreground" id={`recruit-${runId}`}>
          Faire venir des joueurs
        </h2>
        <RecruitPill state={state} />
        <div className="ml-auto flex items-center gap-2">
          <button
            aria-controls={bodyId}
            aria-expanded={open}
            className="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-medium text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
            onClick={() => setOpen((v) => !v)}
            type="button"
          >
            {open ? "Fermer" : "Réglages et lien"}
            <ChevronDown aria-hidden className={`size-4 transition-transform ${open ? "rotate-180" : ""}`} />
          </button>
          <InviteFriendsButton participantIds={participantIds} runId={runId} />
        </div>
      </div>
      {!open && listed ? <ListingPreview pitch={state.pitch ?? ""} plannedFor={state.plannedFor} /> : null}
      {open ? (
        <div className="grid grid-cols-1 gap-4" id={bodyId}>
          {openness !== null && openness !== undefined ? <div className="border-t border-border pt-4">{openness}</div> : null}
          <div className="border-t border-border pt-4">{link}</div>
        </div>
      ) : null}
    </section>
  );
}
