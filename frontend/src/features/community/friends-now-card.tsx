"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";

import { useAuth } from "@/features/auth/auth-context";
import { FriendsOpenRuns } from "@/features/personal-runs/friends-open-runs";
import { FriendIdentity } from "./friend-identity";
import { FriendSuggestions } from "./friend-suggestions";
import { fetchFriendsNow, type FriendPlaying, type FriendRecent } from "./community-friends-api";
import { timeLabel } from "./notification-content";
import { PRESENCE_TONE_CLASSES, presenceStateLabel, presenceTone } from "./rich-presence";

export const FRIENDS_NOW_KEY = ["friends-now"] as const;

const REFRESH_MS = 60_000;

const KIND_LABELS: Record<string, string> = { event: "Événement", run: "Run perso" };

/** Where the viewer may follow the session, or null when they have no access to it. */
export function sessionHref(friend: FriendPlaying): string | null {
  if (friend.eventId !== null) return `/evenements/${friend.eventId}`;
  if (friend.runId !== null) return `/runs/${friend.runId}`;
  return null;
}

/**
 * « Mes amis en ce moment » (story 43.5): the friends playing, then those active in the last day. Refreshed on
 * focus and every minute; no realtime topic. Without a friend, the « Tu as joué avec » suggestions and the directory.
 */
export function FriendsNowCard() {
  const { user } = useAuth();
  const { data, dataUpdatedAt } = useQuery({
    queryKey: FRIENDS_NOW_KEY,
    queryFn: fetchFriendsNow,
    enabled: user !== null,
    staleTime: REFRESH_MS / 2,
    refetchInterval: REFRESH_MS,
    refetchOnWindowFocus: true,
    retry: false,
  });

  if (user === null || !data) return null;

  return (
    <section aria-labelledby="friends-now" className="grid gap-3 rounded-xl border border-border bg-surface p-4">
      <h2 className="font-heading text-lg font-semibold text-foreground" id="friends-now">
        Mes amis en ce moment
      </h2>
      {!data.hasFriends ? (
        <div className="grid gap-3">
          <p className="text-sm text-muted-foreground">
            Ajoute des amis pour voir ici à quoi ils jouent.{" "}
            <Link className="font-medium text-accent-text hover:underline" href="/joueurs">
              Parcourir l&apos;annuaire
            </Link>
          </p>
          <FriendSuggestions limit={4} title="Tu as joué avec" />
        </div>
      ) : data.playing.length === 0 && data.recent.length === 0 ? (
        <p className="text-sm text-muted-foreground">Aucun de tes amis n&apos;a joué ces dernières 24 h.</p>
      ) : (
        <ul className="grid gap-2" role="list">
          {data.playing.map((friend) => (
            <PlayingRow friend={friend} key={friend.userId} />
          ))}
          {data.recent.map((friend) => (
            <RecentRow friend={friend} key={friend.userId} now={dataUpdatedAt} />
          ))}
        </ul>
      )}
      {/* Story 43.14: the drafts friends opened to the member, joined from here too. */}
      {data.hasFriends ? <FriendsOpenRuns compact /> : null}
    </section>
  );
}

function PlayingRow({ friend }: { friend: FriendPlaying }) {
  const href = sessionHref(friend);
  const kind = friend.kind === null ? null : KIND_LABELS[friend.kind];
  // Story 43.7: the state in the pill, the progress next to the game while playing.
  const tone = PRESENCE_TONE_CLASSES[presenceTone(friend)];
  const progress = friend.slotState === "playing" && friend.progressPercent !== null ? `${friend.progressPercent} %` : null;
  return (
    <li className="flex items-center gap-3">
      <FriendIdentity card={friend} link />
      <span className="grid min-w-0 justify-items-end gap-0.5 text-right text-xs">
        <span className={`inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 font-semibold ${tone.pill}`}>
          <span aria-hidden className={`size-1.5 rounded-full ${tone.dot}`} />
          {presenceStateLabel(friend)}
        </span>
        <span className="truncate text-muted-foreground">
          {[friend.game, progress, kind].filter((part) => part !== null && part !== undefined).join(" · ")}
          {friend.title !== null ? (
            <>
              {" · "}
              {href !== null ? (
                <Link className="font-medium text-accent-text hover:underline" href={href}>
                  {friend.title}
                </Link>
              ) : (
                friend.title
              )}
            </>
          ) : null}
        </span>
      </span>
    </li>
  );
}

function RecentRow({ friend, now }: { friend: FriendRecent; now: number }) {
  return (
    <li className="flex items-center gap-3">
      <FriendIdentity card={friend} link />
      <span className="truncate text-right text-xs text-muted-foreground">
        {friend.game !== null ? `${friend.game}, ` : "A joué "}
        {timeLabel(friend.finishedAt, new Date(now))}
      </span>
    </li>
  );
}
