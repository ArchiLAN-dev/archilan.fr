"use client";

import { useEffect, useState } from "react";
import { Radio } from "lucide-react";
import { isFeedEvent as isOverlayFeedEvent, type FeedEvent as OverlayFeedEvent } from "@/features/overlay/overlay-api";
import { fetchSubscribeToken } from "@/features/realtime/realtime-api";
import { fetchSessionFeed, type FeedEvent } from "./feed-api";
import { RunTimeline } from "./run-timeline";

/**
 * The run timeline, live. Loads the persisted feed (so a reload or a late join keeps the history), then
 * subscribes to the Mercure `runs/{id}/feed` topic and merges each new item find in - deduped by
 * finder + origin check so an event that is both in the initial snapshot and arrives live is not
 * counted twice (the persisted timestamp is second-precision, the live one is not, so time can't dedup).
 *
 * Item finds, hints and goals (they all carry a sender slot - story 32.12), matching what is
 * persisted; chat/join/system frames are ignored. Goal markers on the live chart derive from the
 * feed's goal events inside RunTimeline (no `goals` prop here - the recap's podium stays the
 * authoritative source on its own page).
 */
export function LiveRunTimeline({ sessionId }: { sessionId: string }) {
  const [events, setEvents] = useState<FeedEvent[]>([]);
  const [connected, setConnected] = useState(false);

  useEffect(() => {
    let on = true;
    void fetchSessionFeed(sessionId).then((initial) => {
      if (on) setEvents((live) => mergeMany(initial, live));
    });
    return () => {
      on = false;
    };
  }, [sessionId]);

  useEffect(() => {
    let cancelled = false;
    let source: EventSource | null = null;
    let reconnect: ReturnType<typeof setTimeout> | null = null;
    // Whether a connection was already established once - a later onopen is then a *re*connect.
    let hadConnection = false;

    function connect(token: string, hubUrl: string, topic: string): void {
      if (cancelled) return;
      const url = new URL(hubUrl);
      url.searchParams.set("topic", topic);
      url.searchParams.set("authorization", token);
      source = new EventSource(url.toString());

      source.onopen = () => {
        if (cancelled) return;
        setConnected(true);
        // Mercure is pub/sub with no replay: frames pushed during an outage are gone from the
        // stream. On reconnect, re-pull the persisted feed and merge - the dedup keys absorb the
        // overlap, so the gap fills without a manual reload (story 32.13).
        if (hadConnection) {
          void fetchSessionFeed(sessionId).then((snapshot) => {
            if (!cancelled) setEvents((live) => mergeMany(live, snapshot));
          });
        }
        hadConnection = true;
      };
      source.onmessage = (event) => {
        if (typeof event.data !== "string") return;
        let parsed: unknown;
        try {
          parsed = JSON.parse(event.data);
        } catch {
          return;
        }
        if (!isOverlayFeedEvent(parsed)) return;
        const normalized = normalize(parsed);
        if (normalized === null) return;
        setEvents((prev) => mergeMany(prev, [normalized]));
      };
      source.onerror = () => {
        source?.close();
        source = null;
        if (!cancelled) {
          setConnected(false);
          reconnect = setTimeout(() => void init(), 5_000);
        }
      };
    }

    async function init(): Promise<void> {
      const payload = await fetchSubscribeToken(`/sessions/${sessionId}/feed-token`);
      if (cancelled || !payload || !payload.hubUrl) return;
      connect(payload.token, payload.hubUrl, payload.topic);
    }

    void init();

    return () => {
      cancelled = true;
      source?.close();
      if (reconnect) clearTimeout(reconnect);
    };
  }, [sessionId]);

  if (events.length === 0) {
    return (
      <section className="grid gap-2 rounded-lg border border-border bg-surface p-6 text-center">
        <p className="text-sm text-muted-foreground">
          {connected ? "En direct - en attente des premiers objets trouvés…" : "Connexion au direct…"}
        </p>
      </section>
    );
  }

  return (
    <div className="grid gap-3">
      {connected ? (
        <span className="inline-flex w-fit items-center gap-1.5 rounded-full bg-accent/15 px-2.5 py-0.5 text-xs font-semibold text-accent-text">
          <Radio aria-hidden className="size-3.5" />
          En direct
        </span>
      ) : null}
      <RunTimeline events={events} />
    </div>
  );
}

/** A live item find, in the persisted shape; null for non-item frames (no finder slot). */
function normalize(event: OverlayFeedEvent): FeedEvent | null {
  const senderSlot = event.sender?.slot ?? null;
  if (senderSlot === null) {
    return null;
  }
  return {
    id: `${event.timestamp}-${senderSlot}-${event.location?.id ?? "x"}`,
    type: event.type,
    text: event.text ?? "",
    occurredAt: event.timestamp,
    item: { id: event.item?.id ?? null, name: event.item?.name ?? null, flags: event.item?.flags ?? null },
    location: { id: event.location?.id ?? null, name: event.location?.name ?? null },
    sender: { slot: senderSlot, name: event.sender?.name ?? null, game: event.sender?.game ?? null },
    receiver: { slot: event.receiver?.slot ?? null, name: event.receiver?.name ?? null, game: event.receiver?.game ?? null },
  };
}

/**
 * Dedup key between the persisted snapshot and the live stream. An item lives at one location and
 * is found once by one finder; a slot reaches its goal once; a hint for one location repeats
 * verbatim when AP re-broadcasts it. Anything unresolvable falls back to the event id (no dedup).
 */
function findKey(event: FeedEvent): string | null {
  if (event.type === "goal") {
    return event.sender.slot !== null ? `goal:${event.sender.slot}` : event.id;
  }
  const prefix = event.type === "hint" ? "hint" : "item";
  return event.sender.slot !== null && event.location.id !== null
    ? `${prefix}:${event.sender.slot}:${event.location.id}`
    : event.id;
}

function mergeMany(base: FeedEvent[], incoming: FeedEvent[]): FeedEvent[] {
  const seen = new Set(base.map(findKey));
  const merged = [...base];
  for (const event of incoming) {
    const key = findKey(event);
    if (seen.has(key)) continue;
    seen.add(key);
    merged.push(event);
  }
  merged.sort((a, b) => Date.parse(a.occurredAt) - Date.parse(b.occurredAt));
  return merged;
}
