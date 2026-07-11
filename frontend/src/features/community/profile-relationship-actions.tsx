"use client";

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Check, Flag, UserMinus, UserPlus, UserX, X } from "lucide-react";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import {
  acceptFriendship,
  blockUser,
  declineFriendship,
  fetchRelationship,
  removeFriendship,
  sendFriendRequest,
  unblockUser,
  type Relationship,
} from "./community-friends-api";
import { ProfileReportDialog } from "./profile-report-dialog";

const PRIMARY = "inline-flex min-h-9 cursor-pointer items-center gap-1.5 rounded-full bg-accent px-3.5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50";
const SECONDARY = "inline-flex min-h-9 cursor-pointer items-center gap-1.5 rounded-full border border-border px-3.5 text-sm font-medium text-foreground transition-colors hover:border-accent disabled:opacity-50";

export function ProfileRelationshipActions({ slug, name }: { slug: string; name?: string }) {
  const queryClient = useQueryClient();
  const [busy, setBusy] = useState(false);
  const [reportOpen, setReportOpen] = useState(false);

  // fetchRelationship resolves to null on error/anonymous (never throws), so retry stays off.
  const { data: rel } = useQuery({
    queryKey: ["community-relationship", slug],
    queryFn: () => fetchRelationship(slug),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  // Hidden while loading (undefined), for anonymous viewers (relationship needs auth -> null)
  // and on one's own profile.
  if (rel === undefined || rel === null || rel.state === "self" || rel.state === "blocked") return null;

  async function run(action: () => Promise<Relationship | null>) {
    setBusy(true);
    const next = await action();
    setBusy(false);
    // Mutation endpoints return the new relationship - replace it in the cache directly.
    if (next) queryClient.setQueryData(["community-relationship", slug], next);
  }

  async function respond(accept: boolean) {
    if (rel?.friendshipId == null) return;
    setBusy(true);
    const ok = accept ? await acceptFriendship(rel.friendshipId) : await declineFriendship(rel.friendshipId);
    setBusy(false);
    // accept/decline only return ok - refetch the relationship like the old manual reload.
    if (ok) await queryClient.invalidateQueries({ queryKey: ["community-relationship", slug] });
  }

  return (
    <div className="flex flex-wrap items-center gap-2">
      {rel.state === "none" && (
        <>
          <button className={PRIMARY} disabled={busy} onClick={() => run(() => sendFriendRequest(slug))} type="button">
            <UserPlus aria-hidden className="size-4" /> Ajouter en ami
          </button>
          <button className={SECONDARY} disabled={busy} onClick={() => run(() => blockUser(slug))} type="button">
            <UserX aria-hidden className="size-4" /> Bloquer
          </button>
        </>
      )}

      {rel.state === "outgoing" && (
        <button className={SECONDARY} disabled={busy} onClick={() => run(() => removeFriendship(slug))} type="button">
          <X aria-hidden className="size-4" /> Annuler la demande
        </button>
      )}

      {rel.state === "incoming" && (
        <>
          <button className={PRIMARY} disabled={busy} onClick={() => respond(true)} type="button">
            <Check aria-hidden className="size-4" /> Accepter
          </button>
          <button className={SECONDARY} disabled={busy} onClick={() => respond(false)} type="button">
            <X aria-hidden className="size-4" /> Refuser
          </button>
        </>
      )}

      {rel.state === "friends" && (
        <button className={SECONDARY} disabled={busy} onClick={() => run(() => removeFriendship(slug))} type="button">
          <UserMinus aria-hidden className="size-4" /> Retirer des amis
        </button>
      )}

      {rel.state === "blocking" && (
        <button className={SECONDARY} disabled={busy} onClick={() => run(() => unblockUser(slug))} type="button">
          Débloquer
        </button>
      )}

      <button className={SECONDARY} onClick={() => setReportOpen(true)} type="button">
        <Flag aria-hidden className="size-4" /> Signaler
      </button>

      {reportOpen ? <ProfileReportDialog name={name ?? slug} onClose={() => setReportOpen(false)} slug={slug} /> : null}
    </div>
  );
}
