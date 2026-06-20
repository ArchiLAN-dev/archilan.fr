"use client";

import { useEffect, useState } from "react";
import { Check, Flag, UserMinus, UserPlus, UserX, X } from "lucide-react";

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
  const [rel, setRel] = useState<Relationship | null>(null);
  const [ready, setReady] = useState(false);
  const [busy, setBusy] = useState(false);
  const [reportOpen, setReportOpen] = useState(false);

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      const r = await fetchRelationship(slug);
      if (!cancelled) {
        setRel(r);
        setReady(true);
      }
    })();
    return () => { cancelled = true; };
  }, [slug]);

  // Hidden for anonymous viewers (relationship needs auth -> null) and on one's own profile.
  if (!ready || rel === null || rel.state === "self" || rel.state === "blocked") return null;

  async function run(action: () => Promise<Relationship | null>) {
    setBusy(true);
    const next = await action();
    setBusy(false);
    if (next) setRel(next);
  }

  async function respond(accept: boolean) {
    if (rel?.friendshipId == null) return;
    setBusy(true);
    const ok = accept ? await acceptFriendship(rel.friendshipId) : await declineFriendship(rel.friendshipId);
    setBusy(false);
    if (ok) setRel(await fetchRelationship(slug));
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
