"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { Gamepad2, Users } from "lucide-react";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { CommunityLoadingSkeleton } from "./community-loading-skeleton";

import { fetchFriendsFeed, fetchProfileActivity, type ActivityItem } from "./community-feed-api";
import { KudosButton } from "./kudos-button";

/** One actor's recent activity, on their public profile (audience-gated server-side). */
export function ProfileActivity({ slug }: { slug: string }) {
  // fetchProfileActivity resolves to null on error (never throws), so retry stays off like the old one-shot effect.
  const { data: items } = useQuery({
    queryKey: ["community-profile-activity", slug],
    queryFn: () => fetchProfileActivity(slug),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  // Nothing while loading (undefined), on error (null) or when empty - same as before.
  if (items === undefined || items === null || items.length === 0) return null;

  return (
    <section className="grid gap-3">
      <h2 className="font-heading text-lg font-semibold text-foreground">Activité récente</h2>
      <ul className="grid gap-2" role="list">
        {items.map((item, i) => (
          <ActivityRow item={item} key={`${item.type}-${item.occurredAt}-${i}`} showActor={false} />
        ))}
      </ul>
    </section>
  );
}

/** The viewer's feed: their own + their friends' activity. */
export function CommunityFeedPanel() {
  const { data: items, isLoading } = useQuery({
    queryKey: ["community-feed"],
    queryFn: () => fetchFriendsFeed(),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  if (isLoading) {
    return <CommunityLoadingSkeleton rows={4} />;
  }

  if (items === undefined || items === null) {
    return <p className="text-sm text-muted-foreground">Impossible de charger le fil d&apos;activité.</p>;
  }

  if (items.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        Rien pour l&apos;instant - ajoute des amis et termine des runs pour voir l&apos;activité ici.
      </p>
    );
  }

  return (
    <ul className="grid gap-2" role="list">
      {items.map((item, i) => (
        <ActivityRow item={item} key={`${item.actor?.slug ?? ""}-${item.type}-${item.occurredAt}-${i}`} showActor />
      ))}
    </ul>
  );
}

function ActivityRow({ item, showActor }: { item: ActivityItem; showActor: boolean }) {
  return (
    <li className="flex items-start gap-3 rounded-lg border border-border bg-surface px-3 py-2.5">
      <span aria-hidden className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-accent/15 text-accent-text">
        {item.type === "friendship" ? <Users className="size-3.5" /> : <Gamepad2 className="size-3.5" />}
      </span>
      <div className="min-w-0 flex-1 text-sm">
        <p className="text-foreground">
          {showActor && item.actor ? (
            <>
              <Link className="font-semibold hover:text-accent-text" href={`/joueurs/${item.actor.slug}`}>
                {item.actor.displayName ?? item.actor.slug}
              </Link>
              {item.actor.playing ? (
                <span
                  aria-label="En jeu"
                  className="ml-1 inline-block size-1.5 animate-pulse rounded-full bg-emerald-400 align-middle"
                  title="En jeu"
                />
              ) : null}
            </>
          ) : null}{" "}
          <ActivityText item={item} />
        </p>
        <div className="mt-1 flex items-center gap-2">
          <time className="text-xs text-muted-foreground" dateTime={item.occurredAt}>
            {relativeTime(item.occurredAt)}
          </time>
          {item.kudosTargetType && item.kudosTargetId ? (
            <KudosButton
              initialCount={item.kudosCount}
              initialGiven={item.viewerHasKudos}
              targetId={item.kudosTargetId}
              targetType={item.kudosTargetType}
            />
          ) : null}
        </div>
      </div>
    </li>
  );
}

function ActivityText({ item }: { item: ActivityItem }) {
  if (item.type === "friendship") {
    return (
      <span className="text-muted-foreground">
        est devenu ami avec{" "}
        {item.withSlug ? (
          <Link className="font-medium text-foreground hover:text-accent-text" href={`/joueurs/${item.withSlug}`}>
            {item.withName ?? item.withSlug}
          </Link>
        ) : (
          "un membre"
        )}
      </span>
    );
  }

  return (
    <span className="text-muted-foreground">
      a terminé{" "}
      {item.sessionId !== null && item.recapAccessible === true ? (
        <Link className="font-medium text-foreground hover:text-accent-text" href={`/parties/${item.sessionId}`}>
          {item.game ?? "une run"}
        </Link>
      ) : (
        <span className="font-medium text-foreground">{item.game ?? "une run"}</span>
      )}
      {item.event ? <span className="text-muted-foreground"> · {item.event}</span> : null}
    </span>
  );
}

function relativeTime(iso: string): string {
  const ts = new Date(iso).getTime();
  if (Number.isNaN(ts)) return "";
  const diff = Date.now() - ts;
  if (diff < 60_000) return "à l'instant";
  if (diff < 3_600_000) return `il y a ${Math.floor(diff / 60_000)} min`;
  if (diff < 86_400_000) return `il y a ${Math.floor(diff / 3_600_000)} h`;
  return `il y a ${Math.floor(diff / 86_400_000)} j`;
}
