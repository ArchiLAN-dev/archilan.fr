"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";

import { useAuth } from "@/features/auth/auth-context";
import { MemberAvatar } from "@/features/community/member-avatar";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchWeeklyRunFriends, type WeeklyRunFriend } from "./weekly-runs-api";

function formatDuration(seconds: number): string {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = seconds % 60;
  return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`;
}

export function weeklyFriendStatus(friend: WeeklyRunFriend): string {
  if (friend.status === "goal") return friend.completionTimeSeconds !== null ? formatDuration(friend.completionTimeSeconds) : "Objectif atteint";
  return friend.status === "launched" ? "Lancée" : "Inscrit";
}

/**
 * « Tes amis cette semaine » (story 43.8): the viewer and their friends in this weekly run, ranked by time. Shown
 * only to a signed-in member with at least one friend taking part.
 */
export function WeeklyRunFriends({ weeklyRunId }: { weeklyRunId: string }) {
  const { user } = useAuth();
  const { data } = useQuery({
    queryKey: ["weekly-run-friends", weeklyRunId],
    queryFn: () => fetchWeeklyRunFriends(weeklyRunId),
    enabled: user !== null,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  if (user === null || !data || !data.some((friend) => !friend.isViewer)) return null;

  return (
    <section aria-labelledby={`weekly-friends-${weeklyRunId}`} className="grid gap-2">
      <h3 className="text-sm font-semibold text-foreground" id={`weekly-friends-${weeklyRunId}`}>
        Tes amis cette semaine
      </h3>
      <ol className="grid gap-1.5">
        {data.map((friend) => (
          <li
            className={[
              "flex items-center gap-3 rounded px-3 py-2",
              friend.isViewer ? "bg-accent/10 ring-1 ring-accent/30" : "bg-surface-2/50",
            ].join(" ")}
            key={friend.userId}
          >
            <MemberAvatar
              avatarAnimatedUrl={friend.avatarAnimatedUrl}
              avatarUrl={friend.avatarUrl}
              frame={friend.avatarFrame}
              name={friend.displayName ?? friend.slug}
              size={28}
            />
            <Link className="min-w-0 flex-1 truncate text-sm text-foreground hover:text-accent-text" href={`/joueurs/${friend.slug}`}>
              {friend.displayName ?? friend.slug}
              {friend.isViewer ? <span className="ml-1.5 text-xs text-accent-text">(toi)</span> : null}
            </Link>
            <span
              className={[
                "shrink-0 text-sm",
                friend.status === "goal" ? "font-mono font-semibold text-success" : "text-muted-foreground",
              ].join(" ")}
            >
              {weeklyFriendStatus(friend)}
            </span>
          </li>
        ))}
      </ol>
    </section>
  );
}
