"use client";

import { useQuery } from "@tanstack/react-query";

import { useAuth } from "@/features/auth/auth-context";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchEventFriends, fetchEventFriendsBatch, type FriendCard } from "./community-friends-api";
import { MemberAvatar } from "./member-avatar";

const VISIBLE = 3;

export function eventFriendsLine(count: number): string {
  return count === 1 ? "1 de tes amis participe" : `${count} de tes amis participent`;
}

type Props = {
  eventId: string;
  /** On a list: every event shown, so all the cards share one grouped call. */
  eventIds?: string[];
};

/**
 * « N de tes amis participent » (story 43.4), with 3 avatars and a counter. Loaded client-side (the SSR is always
 * anonymous); nothing for a visitor or when no friend is registered.
 */
export function EventFriendsBadge({ eventId, eventIds }: Props) {
  const { user } = useAuth();
  const single = useQuery({
    queryKey: ["event-friends", eventId],
    queryFn: () => fetchEventFriends(eventId),
    enabled: user !== null && eventIds === undefined,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  const grouped = useQuery({
    queryKey: ["event-friends-batch", eventIds ?? []],
    queryFn: () => fetchEventFriendsBatch(eventIds ?? []),
    enabled: user !== null && eventIds !== undefined,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  const friends: FriendCard[] = (eventIds === undefined ? single.data : grouped.data?.[eventId]) ?? [];
  if (user === null || friends.length === 0) return null;

  const hidden = friends.length - VISIBLE;
  const names = friends.map((card) => card.displayName ?? card.slug).join(", ");

  return (
    <p className="inline-flex items-center gap-2 text-sm text-muted-foreground" title={names}>
      <span className="flex -space-x-2">
        {friends.slice(0, VISIBLE).map((card) => (
          <MemberAvatar
            avatarUrl={card.avatarUrl}
            className="ring-2 ring-background"
            framing={card.avatarFraming}
            hoverVideo={false}
            key={card.userId}
            name={card.displayName ?? card.slug}
            size={24}
          />
        ))}
        {hidden > 0 ? (
          <span className="z-10 grid size-6 place-items-center rounded-full bg-surface-2 text-[10px] font-semibold text-foreground ring-2 ring-background">
            +{hidden}
          </span>
        ) : null}
      </span>
      <span className="font-medium text-foreground">{eventFriendsLine(friends.length)}</span>
    </p>
  );
}
