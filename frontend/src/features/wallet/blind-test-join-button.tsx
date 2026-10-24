"use client";

import { useQuery } from "@tanstack/react-query";
import { Music } from "lucide-react";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchBlindTestJoinUrl } from "./blind-test-api";

/**
 * Story 41.40: a registrant's way into the event's blind test, shown only while they may play (registered, blind
 * test open, event running). The link is signed by the site; the app reads nothing else.
 */
export function BlindTestJoinButton({ eventId }: { eventId: string }) {
  const { data: url } = useQuery({
    queryKey: ["blind-test-join", eventId],
    queryFn: () => fetchBlindTestJoinUrl(eventId),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  if (!url) return null;

  return (
    <a
      className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-accent/50 bg-accent/10 px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
      href={url}
      rel="noopener noreferrer"
      target="_blank"
    >
      <Music aria-hidden className="size-4 text-accent-text" />
      Jouer au blind test
    </a>
  );
}
