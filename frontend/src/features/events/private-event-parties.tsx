"use client";

import { useQuery } from "@tanstack/react-query";

import { useAuth } from "@/features/auth/auth-context";
import { fetchEventRecapIndex } from "@/features/recap/recap-api";
import { EventPartiesSection } from "./event-parties";

/**
 * Story 4.23: a private event's games, loaded in the browser where the member's session reaches the API (see
 * PrivateRecapFallback, story 32.5). Shows nothing to a visitor or a member who did not take part.
 */
export function PrivateEventParties({ eventId }: { eventId: string }) {
  const { user, loading } = useAuth();
  const authenticated = !loading && user !== null;

  const { data } = useQuery({
    queryKey: ["private-event-parties", eventId],
    queryFn: () => fetchEventRecapIndex(eventId),
    enabled: authenticated,
    staleTime: 60_000,
  });

  return <EventPartiesSection parties={data ?? []} />;
}
