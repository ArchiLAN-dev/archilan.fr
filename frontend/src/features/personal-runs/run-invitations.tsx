"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Check, Mail, Search, UserPlus, X } from "lucide-react";

import { Dialog } from "@/components/ui/dialog";
import { fetchFriends, type FriendCard } from "@/features/community/community-friends-api";
import { FriendIdentity } from "@/features/community/friend-identity";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import {
  answerRunInvitation,
  fetchMyRunInvitations,
  fetchRunInvitations,
  sendRunInvitations,
  type RunInvitation,
  type RunInvitationStatus,
} from "./run-invitations-api";

const runInvitationsKey = (runId: string) => ["run-invitations", runId];
export const MY_RUN_INVITATIONS_KEY = ["my-run-invitations"];

/** Why a friend cannot be picked: already in, or an invitation still open. Null when they can. */
export function unavailableReason(friend: FriendCard, participantIds: ReadonlySet<string>, invitations: RunInvitation[]): string | null {
  if (participantIds.has(friend.userId)) return "Participe déjà";
  const invitation = invitations.find((i) => i.invitee.userId === friend.userId);
  if (invitation?.status === "pending") return "Invité";
  if (invitation?.status === "accepted") return "A rejoint";
  return null;
}

export function matchesSearch(friend: FriendCard, search: string): boolean {
  const term = search.trim().toLowerCase();
  if (term === "") return true;
  return (friend.displayName ?? "").toLowerCase().includes(term) || friend.slug.toLowerCase().includes(term);
}

/**
 * « Inviter des amis » (story 43.1): the owner picks friends; each gets a notification and joins in one click.
 * The invite link stays, for anyone not in the friends list.
 */
export function InviteFriendsButton({ runId, participantIds }: { runId: string; participantIds: string[] }) {
  const [open, setOpen] = useState(false);

  return (
    <>
      <button
        className="inline-flex items-center gap-2 rounded border border-border bg-surface px-3 py-1.5 text-sm font-semibold text-foreground transition-colors hover:border-accent"
        onClick={() => setOpen(true)}
        type="button"
      >
        <UserPlus aria-hidden className="size-4" />
        Inviter des amis
      </button>
      <Dialog description="Ils reçoivent une notification et rejoignent la partie en un clic." onOpenChange={setOpen} open={open} title="Inviter des amis">
        <InviteFriendsForm onDone={() => setOpen(false)} participantIds={participantIds} runId={runId} />
      </Dialog>
    </>
  );
}

function InviteFriendsForm({ runId, participantIds, onDone }: { runId: string; participantIds: string[]; onDone: () => void }) {
  const queryClient = useQueryClient();
  const [search, setSearch] = useState("");
  const [picked, setPicked] = useState<Set<string>>(new Set());
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const friendsQuery = useQuery({ queryKey: ["community-friends"], queryFn: fetchFriends, staleTime: DEFAULT_STALE_TIME, retry: false });
  const invitationsQuery = useQuery({ queryKey: runInvitationsKey(runId), queryFn: () => fetchRunInvitations(runId), staleTime: DEFAULT_STALE_TIME, retry: false });

  const participants = new Set(participantIds);
  const invitations = invitationsQuery.data ?? [];
  const friends = friendsQuery.data?.friends ?? [];
  const shown = friends.filter((friend) => matchesSearch(friend, search));

  function toggle(userId: string) {
    setPicked((prev) => {
      const next = new Set(prev);
      if (next.has(userId)) next.delete(userId);
      else next.add(userId);
      return next;
    });
  }

  async function handleSend() {
    setBusy(true);
    setMessage(null);
    const result = await sendRunInvitations(runId, [...picked]);
    setBusy(false);
    if (!result.ok) {
      setMessage(result.message);
      return;
    }
    await queryClient.invalidateQueries({ queryKey: runInvitationsKey(runId) });
    onDone();
  }

  if (friendsQuery.isLoading) {
    return <p className="px-5 py-4 text-sm text-muted-foreground">Chargement de tes amis…</p>;
  }

  if (friends.length === 0) {
    return (
      <p className="px-5 py-4 text-sm text-muted-foreground">
        Tu n&apos;as pas encore d&apos;amis sur le site. Partage le lien d&apos;invitation, ou ajoute les joueurs avec qui tu as joué
        depuis « Mes amis ».
      </p>
    );
  }

  return (
    <div className="grid gap-3 px-5 py-4">
      <label className="relative block">
        <span className="sr-only">Chercher un ami</span>
        <Search aria-hidden className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
        <input
          className="w-full rounded border border-border bg-background py-2 pl-9 pr-3 text-sm text-foreground"
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Chercher par pseudo"
          type="search"
          value={search}
        />
      </label>
      <ul className="grid max-h-80 gap-1 overflow-y-auto" role="list">
        {shown.map((friend) => {
          const reason = unavailableReason(friend, participants, invitations);
          return (
            <li key={friend.userId}>
              <label className={`flex items-center gap-3 rounded px-2 py-1.5 ${reason === null ? "cursor-pointer hover:bg-surface-2" : "opacity-60"}`}>
                <input
                  checked={picked.has(friend.userId)}
                  className="size-4 accent-[var(--color-accent)]"
                  disabled={reason !== null}
                  onChange={() => toggle(friend.userId)}
                  type="checkbox"
                />
                <FriendIdentity card={friend} />
                {reason !== null ? <span className="shrink-0 text-xs text-muted-foreground">{reason}</span> : null}
              </label>
            </li>
          );
        })}
      </ul>
      {message !== null ? <p className="text-sm text-danger">{message}</p> : null}
      <div className="flex justify-end">
        <button
          className="inline-flex items-center gap-2 rounded bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
          disabled={busy || picked.size === 0}
          onClick={() => void handleSend()}
          type="button"
        >
          <Mail aria-hidden className="size-4" />
          {picked.size > 1 ? `Inviter ${picked.size} amis` : "Inviter"}
        </button>
      </div>
    </div>
  );
}

const STATUS_LABELS: Record<RunInvitationStatus, string> = {
  pending: "En attente",
  accepted: "A rejoint",
  declined: "Refusée",
  closed: "Close",
};

/** The owner follows their invitations by name (story 43.1). Nothing when none was sent. */
export function RunInvitationsList({ runId }: { runId: string }) {
  const { data } = useQuery({ queryKey: runInvitationsKey(runId), queryFn: () => fetchRunInvitations(runId), staleTime: DEFAULT_STALE_TIME, retry: false });
  if (!data || data.length === 0) return null;

  return (
    <section className="rounded-lg border border-border bg-surface p-4">
      <h2 className="mb-3 text-sm font-semibold text-foreground">Invitations</h2>
      <ul className="grid gap-2" role="list">
        {data.map((invitation) => (
          <li className="flex items-center gap-3" key={invitation.invitationId}>
            <FriendIdentity card={invitation.invitee} link />
            <span className="shrink-0 rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground">{STATUS_LABELS[invitation.status]}</span>
          </li>
        ))}
      </ul>
    </section>
  );
}

/**
 * The member's pending invitations, on top of « Mes parties » (story 43.1): join in one click, or decline.
 * Nothing when there is none.
 */
export function MyRunInvitations() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const [busy, setBusy] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const { data } = useQuery({ queryKey: MY_RUN_INVITATIONS_KEY, queryFn: fetchMyRunInvitations, staleTime: DEFAULT_STALE_TIME, retry: false });

  if (!data || data.length === 0) return null;

  async function answer(invitationId: string, choice: "accept" | "decline") {
    setBusy(invitationId);
    setMessage(null);
    const result = await answerRunInvitation(invitationId, choice);
    setBusy(null);
    await queryClient.invalidateQueries({ queryKey: MY_RUN_INVITATIONS_KEY });
    if (!result.ok) {
      setMessage(result.message);
      return;
    }
    if (choice === "accept") {
      router.push(`/runs/${result.runId}`);
    }
  }

  return (
    <section className="rounded-lg border border-accent/40 bg-accent/5 p-4">
      <h2 className="mb-3 font-heading text-lg font-semibold text-foreground">Invitations</h2>
      <ul className="grid gap-2" role="list">
        {data.map((invitation) => (
          <li className="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-surface px-3 py-2" key={invitation.invitationId}>
            <span className="min-w-0 flex-1 text-sm text-foreground">
              <strong>{invitation.inviter?.displayName ?? invitation.inviter?.slug ?? "Un ami"}</strong> t&apos;invite dans{" "}
              <strong>« {invitation.runTitle} »</strong>
            </span>
            <div className="flex shrink-0 items-center gap-1">
              <button
                className="inline-flex items-center gap-1.5 rounded-full bg-accent px-3 py-1.5 text-xs font-semibold text-white hover:bg-accent-hover disabled:opacity-50"
                disabled={busy !== null}
                onClick={() => void answer(invitation.invitationId, "accept")}
                type="button"
              >
                <Check aria-hidden className="size-3.5" />
                Rejoindre
              </button>
              <button
                aria-label={`Refuser l'invitation dans « ${invitation.runTitle} »`}
                className="inline-flex size-8 items-center justify-center rounded-full border border-border text-muted-foreground hover:text-foreground disabled:opacity-50"
                disabled={busy !== null}
                onClick={() => void answer(invitation.invitationId, "decline")}
                type="button"
              >
                <X aria-hidden className="size-4" />
              </button>
            </div>
          </li>
        ))}
      </ul>
      {message !== null ? <p className="mt-2 text-sm text-danger">{message}</p> : null}
    </section>
  );
}
