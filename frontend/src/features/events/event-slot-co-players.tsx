"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";

import { SlotCoPlayers } from "@/features/personal-runs/slot-co-players";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { eventSlotCoPlayersKey, fetchEventSlotCoPlayers, replaceEventSlotCoPlayers } from "./event-slot-co-players-api";

/**
 * Story 11.10: who else plays a slot of an event. An admin or the slot's holder adds the event's confirmed
 * registrants; a co-player gets the slot (name, connection, patch, tracking) even when none of their own slots went
 * into the session. Nothing shown to whoever may not see the roster.
 */
export function EventSlotCoPlayers({ eventId, slotId }: { eventId: string; slotId: string }) {
  const queryClient = useQueryClient();
  const key = eventSlotCoPlayersKey(eventId, slotId);
  const { data } = useQuery({
    queryKey: key,
    queryFn: () => fetchEventSlotCoPlayers(eventId, slotId),
    staleTime: DEFAULT_STALE_TIME,
  });

  if (!data) return null;

  return (
    <SlotCoPlayers
      canManage={data.canManage}
      candidates={data.candidates}
      coPlayers={data.coPlayers}
      onChanged={() => queryClient.invalidateQueries({ queryKey: key })}
      onSave={(userIds) => replaceEventSlotCoPlayers(eventId, slotId, userIds)}
    />
  );
}
