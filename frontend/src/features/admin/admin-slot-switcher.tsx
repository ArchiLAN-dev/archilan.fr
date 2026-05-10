"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import type { SlotEntry } from "@/features/reachability/types";

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
  const [slots, setSlots] = useState<SlotEntry[]>([]);
  const [filter, setFilter] = useState("");

  useEffect(() => {
    apiFetch(`${env.apiBaseUrl}/sessions/${sessionId}/players`)
      .then((r) => r.json())
      .then((json: { data?: { slots?: Record<string, { slot_name: string }> } }) => {
        const entries: SlotEntry[] = Object.entries(json.data?.slots ?? {})
          .map(([index, s]) => ({ index, name: s.slot_name }))
          .sort((a, b) => Number(a.index) - Number(b.index));
        setSlots(entries);
      })
      .catch(() => undefined);
  }, [sessionId]);

  const filtered = filter.trim()
    ? slots.filter((s) => s.name.toLowerCase().includes(filter.trim().toLowerCase()))
    : slots;

  if (slots.length === 0) return null;

  return (
    <div className="flex shrink-0 flex-col gap-1.5">
      <label className="text-xs text-muted-foreground" htmlFor="slot-switcher">
        Changer de slot
      </label>
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
