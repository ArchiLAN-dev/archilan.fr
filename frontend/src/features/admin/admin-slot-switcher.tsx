"use client";

import { useQuery } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { useState } from "react";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchSessionSlots } from "./admin-slots-api";

export function SlotSwitcher({
  sessionId,
  eventId,
  currentSlot,
}: {
  sessionId: string;
  eventId: string;
  currentSlot: string;
}) {
  const router = useRouter();
  const [filter, setFilter] = useState("");

  // `null` (fetch failed) renders exactly like the old empty list: the switcher stays hidden.
  const { data } = useQuery({
    queryKey: ["admin-session-slots", sessionId],
    queryFn: () => fetchSessionSlots(sessionId),
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // fetchSessionSlots never throws; the old effect never retried either
  });
  const slots = data ?? [];

  const filtered = filter.trim()
    ? slots.filter((s) => s.name.toLowerCase().includes(filter.trim().toLowerCase()))
    : slots;

  if (slots.length === 0) return null;

  return (
    <div className="flex shrink-0 flex-col gap-1.5">
      <div className="flex items-center gap-2">
        {slots.length > 8 ? (
          <input
            className="h-8 w-40 rounded border border-border bg-surface px-2 text-xs text-foreground placeholder:text-muted-foreground focus:border-accent-text focus:outline-none"
            onChange={(e) => { setFilter(e.target.value); }}
            placeholder="Filtrer…"
            type="search"
            value={filter}
          />
        ) : null}
        <select
          className="h-8 rounded border border-border bg-surface px-2 pr-7 text-xs text-foreground focus:border-accent-text focus:outline-none"
          id="slot-switcher"
          onChange={(e) => {
            if (e.target.value && e.target.value !== currentSlot) {
              router.push(`/admin/evenements/${eventId}/session/${sessionId}/slots/${e.target.value}`);
            }
          }}
          value={currentSlot}
        >
          {filtered.map((s) => (
            <option key={s.index} value={s.index}>
              #{s.index} - {s.name}
            </option>
          ))}
          {filtered.length === 0 ? (
            <option disabled value="">
              Aucun résultat
            </option>
          ) : null}
        </select>
      </div>
    </div>
  );
}
