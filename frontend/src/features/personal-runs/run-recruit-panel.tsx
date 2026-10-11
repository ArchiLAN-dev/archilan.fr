"use client";

import type { ReactNode } from "react";
import { UsersRound } from "lucide-react";

import { InviteFriendsButton } from "./run-invitations";

/**
 * « Faire venir des joueurs » (story 43.20): one card for the owner with the invitation by name (43.1), who can join
 * on their own (43.14, 43.17, drafts only) and the invite link, instead of three blocks stacked without a hierarchy.
 */
export function RunRecruitPanel({
  runId,
  participantIds,
  openness,
  link,
}: {
  runId: string;
  participantIds: string[];
  /** The openness setting, only on a draft run. */
  openness: ReactNode;
  link: ReactNode;
}) {
  return (
    <section aria-labelledby={`recruit-${runId}`} className="grid gap-4 rounded-lg border border-border bg-surface p-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="grid gap-0.5">
          <h2 className="flex items-center gap-2 text-sm font-semibold text-foreground" id={`recruit-${runId}`}>
            <UsersRound aria-hidden className="size-4 text-accent-text" />
            Faire venir des joueurs
          </h2>
          <p className="text-xs text-muted-foreground">Tes amis invités reçoivent une notification et rejoignent en un clic.</p>
        </div>
        <InviteFriendsButton participantIds={participantIds} runId={runId} />
      </div>
      {openness !== null && openness !== undefined ? <div className="border-t border-border pt-4">{openness}</div> : null}
      <div className="border-t border-border pt-4">{link}</div>
    </section>
  );
}
