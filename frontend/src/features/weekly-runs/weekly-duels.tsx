"use client";

import { useState } from "react";
import Link from "next/link";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Check, Swords, X } from "lucide-react";

import { Dialog } from "@/components/ui/dialog";
import { useAuth } from "@/features/auth/auth-context";
import { fetchFriends } from "@/features/community/community-friends-api";
import { FriendIdentity } from "@/features/community/friend-identity";
import { MemberAvatar } from "@/features/community/member-avatar";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import {
  answerWeeklyDuel,
  challengeFriends,
  fetchWeeklyDuels,
  MAX_DUEL_OPPONENTS,
  weeklyDuelsKey,
  type DuelStanding,
  type WeeklyDuel,
} from "./weekly-duels-api";

function formatDuration(seconds: number): string {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = seconds % 60;
  return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`;
}

export function duelStandingLabel(standing: DuelStanding): string {
  switch (standing.status) {
    case "goal":
      return standing.completionTimeSeconds !== null ? formatDuration(standing.completionTimeSeconds) : "Objectif atteint";
    case "launched":
      return "Lancée";
    case "registered":
      return "Inscrit";
    case "invited":
      return "Pas encore répondu";
    default:
      return "Pas inscrit";
  }
}

/**
 * The open duels the member is in (story 43.15), on one weekly run or on all of them: a challenge to answer, or
 * the mini-ranking until the run ends. Nothing when there is none.
 */
export function WeeklyDuels({ weeklyRunId = null, withGame = false }: { weeklyRunId?: string | null; withGame?: boolean }) {
  const { user } = useAuth();
  const { data } = useQuery({
    queryKey: weeklyDuelsKey(weeklyRunId),
    queryFn: () => fetchWeeklyDuels(weeklyRunId),
    enabled: user !== null,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  if (user === null || !data || data.length === 0) return null;

  return (
    <section aria-label="Duels entre amis" className="grid gap-3">
      {data.map((duel) => (
        <DuelCard duel={duel} key={duel.duelId} withGame={withGame} />
      ))}
    </section>
  );
}

function DuelCard({ duel, withGame }: { duel: WeeklyDuel; withGame: boolean }) {
  const queryClient = useQueryClient();
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const creator = duel.standings.find((standing) => standing.isCreator);

  async function answer(choice: "accept" | "decline") {
    setBusy(true);
    setMessage(null);
    const result = await answerWeeklyDuel(duel.duelId, choice);
    setBusy(false);
    if (!result.ok) setMessage(result.message);
    await queryClient.invalidateQueries({ queryKey: ["weekly-duels"] });
  }

  return (
    <div className="grid gap-2 rounded-lg border border-accent/30 bg-accent/5 px-3 py-3">
      <p className="flex items-center gap-2 text-sm font-semibold text-foreground">
        <Swords aria-hidden className="size-4 text-accent-text" />
        {duel.isCreator ? "Ton duel" : `Duel de ${creator?.displayName ?? creator?.slug ?? "un ami"}`}
        {withGame && duel.gameName !== null ? (
          <Link className="font-normal text-muted-foreground hover:text-accent-text" href="/runs-hebdo">
            · {duel.gameName}
          </Link>
        ) : null}
      </p>
      {duel.myStatus === "pending" ? (
        <div className="flex flex-wrap items-center gap-2">
          <span className="min-w-0 flex-1 text-sm text-muted-foreground">Meilleur temps à l&apos;objectif d&apos;ici la fin de la semaine.</span>
          <button
            className="inline-flex items-center gap-1.5 rounded-full bg-accent px-3 py-1.5 text-xs font-semibold text-white hover:bg-accent-hover disabled:opacity-50"
            disabled={busy}
            onClick={() => void answer("accept")}
            type="button"
          >
            <Check aria-hidden className="size-3.5" />
            Relever le défi
          </button>
          <button
            aria-label="Refuser le duel"
            className="inline-flex size-8 items-center justify-center rounded-full border border-border text-muted-foreground hover:text-foreground disabled:opacity-50"
            disabled={busy}
            onClick={() => void answer("decline")}
            type="button"
          >
            <X aria-hidden className="size-4" />
          </button>
        </div>
      ) : null}
      <ol className="grid gap-1.5">
        {duel.standings.map((standing) => (
          <li
            className={["flex items-center gap-3 rounded px-3 py-1.5", standing.isViewer ? "bg-accent/10 ring-1 ring-accent/30" : "bg-surface-2/50"].join(" ")}
            key={standing.userId}
          >
            <MemberAvatar
              avatarAnimatedUrl={standing.avatarAnimatedUrl}
              avatarUrl={standing.avatarUrl}
              frame={standing.avatarFrame}
              name={standing.displayName ?? standing.slug}
              size={24}
            />
            <span className="min-w-0 flex-1 truncate text-sm text-foreground">
              {standing.displayName ?? standing.slug}
              {standing.isViewer ? <span className="ml-1.5 text-xs text-accent-text">(toi)</span> : null}
            </span>
            <span className={["shrink-0 text-sm", standing.status === "goal" ? "font-mono font-semibold text-success" : "text-muted-foreground"].join(" ")}>
              {duelStandingLabel(standing)}
            </span>
          </li>
        ))}
      </ol>
      {message !== null ? (
        <p className="text-sm text-danger" role="alert">
          {message}
        </p>
      ) : null}
    </div>
  );
}

/** « Défier des amis » on the weekly run's page (story 43.15): up to MAX_DUEL_OPPONENTS friends at once. */
export function ChallengeFriendsButton({ weeklyRunId }: { weeklyRunId: string }) {
  const { user } = useAuth();
  const [open, setOpen] = useState(false);

  if (user === null) return null;

  return (
    <>
      <button
        className="inline-flex items-center gap-2 self-start rounded border border-border bg-surface px-3 py-1.5 text-sm font-semibold text-foreground transition-colors hover:border-accent"
        onClick={() => setOpen(true)}
        type="button"
      >
        <Swords aria-hidden className="size-4" />
        Défier des amis
      </button>
      <Dialog description="Meilleur temps à l'objectif d'ici la fin de l'hebdo. Ils reçoivent une notification et acceptent ou refusent." onOpenChange={setOpen} open={open} title="Défier des amis">
        <ChallengeForm onDone={() => setOpen(false)} weeklyRunId={weeklyRunId} />
      </Dialog>
    </>
  );
}

function ChallengeForm({ weeklyRunId, onDone }: { weeklyRunId: string; onDone: () => void }) {
  const queryClient = useQueryClient();
  const [picked, setPicked] = useState<Set<string>>(new Set());
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const friendsQuery = useQuery({ queryKey: ["community-friends"], queryFn: fetchFriends, staleTime: DEFAULT_STALE_TIME, retry: false });
  const friends = friendsQuery.data?.friends ?? [];

  function toggle(userId: string) {
    setPicked((prev) => {
      const next = new Set(prev);
      if (next.has(userId)) next.delete(userId);
      else if (next.size < MAX_DUEL_OPPONENTS) next.add(userId);
      return next;
    });
  }

  async function send() {
    setBusy(true);
    setMessage(null);
    const result = await challengeFriends(weeklyRunId, [...picked]);
    setBusy(false);
    if (!result.ok) {
      setMessage(result.message);
      return;
    }
    await queryClient.invalidateQueries({ queryKey: ["weekly-duels"] });
    onDone();
  }

  if (friendsQuery.isLoading) {
    return <p className="px-5 py-4 text-sm text-muted-foreground">Chargement de tes amis…</p>;
  }
  if (friends.length === 0) {
    return <p className="px-5 py-4 text-sm text-muted-foreground">Ajoute des amis depuis « Mes amis » pour les défier.</p>;
  }

  return (
    <div className="grid gap-3 px-5 py-4">
      <p className="text-xs text-muted-foreground">
        {picked.size} / {MAX_DUEL_OPPONENTS} amis choisis
      </p>
      <ul className="grid max-h-72 gap-1 overflow-y-auto" role="list">
        {friends.map((friend) => {
          const checked = picked.has(friend.userId);
          return (
            <li key={friend.userId}>
              <label className="flex cursor-pointer items-center gap-3 rounded px-2 py-1.5 hover:bg-surface-2/60">
                <input
                  checked={checked}
                  disabled={!checked && picked.size >= MAX_DUEL_OPPONENTS}
                  onChange={() => toggle(friend.userId)}
                  type="checkbox"
                />
                <FriendIdentity card={friend} />
              </label>
            </li>
          );
        })}
      </ul>
      {message !== null ? (
        <p className="text-sm text-danger" role="alert">
          {message}
        </p>
      ) : null}
      <button
        className="inline-flex items-center justify-center gap-2 rounded bg-accent px-3 py-2 text-sm font-semibold text-white hover:bg-accent-hover disabled:opacity-50"
        disabled={busy || picked.size === 0}
        onClick={() => void send()}
        type="button"
      >
        <Swords aria-hidden className="size-4" />
        Lancer le duel
      </button>
    </div>
  );
}
