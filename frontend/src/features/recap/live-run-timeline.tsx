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
 * Item finds only (they carry a finder slot); hints/chat frames are ignored, matching what is persisted.
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

    function connect(token: string, hubUrl: string, topic: string): void {
      if (cancelled) return;
      const url = new URL(hubUrl);
      url.searchParams.set("topic", topic);
      url.searchParams.set("authorization", token);
      source = new EventSource(url.toString());

      source.onopen = () => {
        if (!cancelled) setConnected(true);
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

/** Unique find key: one item lives at one location, found once by one finder. */
function findKey(event: FeedEvent): string | null {
  return event.sender.slot !== null && event.location.id !== null ? `${event.sender.slot}:${event.location.id}` : event.id;
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
