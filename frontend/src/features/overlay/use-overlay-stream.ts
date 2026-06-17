"use client";

import { useEffect, useLayoutEffect, useRef, useState } from "react";

import { fetchOverlaySubscribe } from "./overlay-api";

type TopicKind = "feed" | "players";

/**
 * Subscribes an overlay (OBS browser source) to one Mercure topic of a session, authenticated by the
 * opaque overlay token. Mirrors the resilient EventSource pattern of PlayerProgressGrid/EventFeed: on
 * error it closes, re-mints a fresh short-lived JWT via the public subscribe endpoint, and reconnects.
 * The single minted JWT authorizes both feed + players topics, so each widget subscribes to just the
 * one it needs. `onEvent` is held in a ref so changing it never re-opens the stream.
 */
export function useOverlayStream<T>(
  sessionId: string,
  kind: TopicKind,
  onEvent: (data: T) => void,
): { connected: boolean } {
  const [connected, setConnected] = useState(false);
  const onEventRef = useRef(onEvent);

  useLayoutEffect(() => {
    onEventRef.current = onEvent;
  });

  useEffect(() => {
    if (!sessionId) return;

    let cancelled = false;
    let es: EventSource | null = null;
    let reconnectTimer: ReturnType<typeof setTimeout> | null = null;

    function connect(hubUrl: string, jwt: string, topics: string[]): void {
      if (cancelled) return;
      const url = new URL(hubUrl);
      for (const topic of topics) url.searchParams.append("topic", topic);
      url.searchParams.set("authorization", jwt);
      const source = new EventSource(url.toString());
      es = source;

      source.onopen = () => {
        if (!cancelled) setConnected(true);
      };

      source.onmessage = (event) => {
        try {
          onEventRef.current(JSON.parse(event.data as string) as T);
        } catch {
          /* ignore malformed SSE frames */
        }
      };

      source.onerror = () => {
        source.close();
        es = null;
        if (cancelled) return;
        setConnected(false);
        reconnectTimer = setTimeout(() => {
          void init();
        }, 5_000);
      };
    }

    async function init(): Promise<void> {
      const sub = await fetchOverlaySubscribe(sessionId);
      if (cancelled || !sub || !sub.hubUrl) return;
      // Subscribe to the widget's primary topic AND the overlay-only test channel (one EventSource,
      // the single JWT authorizes both). Test events thus reach the overlay but never the player pages.
      const primary = sub.topics.find((t) => t.endsWith(`/${kind}`));
      const test = sub.topics.find((t) => t.endsWith("/overlay-test"));
      const topics = [primary, test].filter((t): t is string => typeof t === "string");
      if (topics.length === 0) return;
      connect(sub.hubUrl, sub.token, topics);
    }

    void init();

    return () => {
      cancelled = true;
      es?.close();
      es = null;
      if (reconnectTimer) clearTimeout(reconnectTimer);
    };
  }, [sessionId, kind]);

  return { connected };
}
