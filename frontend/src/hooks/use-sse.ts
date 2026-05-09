"use client";

import { useEffect, useRef, useState } from "react";

const RECONNECT_DELAY_MS = 5_000;
const POLLING_INTERVAL_MS = 30_000;
const DISCONNECT_GRACE_MS = 3_000;

export type SSEStatus = {
  /** True once the SSE connection is open and has not timed out. */
  connected: boolean;
  /** True after the disconnect grace period has elapsed. */
  disconnected: boolean;
  /** True while fallback polling is active. */
  polling: boolean;
  /** Timestamp of the last successfully parsed SSE message, or null if none received yet. */
  lastMessageAt: Date | null;
};

/**
 * Subscribes to a Mercure topic via EventSource.
 * Falls back to polling every 30 s when SSE is unavailable or disconnects.
 * Reconnects automatically after a short delay.
 * Exposes connection state after a 3 s grace period so transient blips don't flash the UI.
 */
export function useSSE<T>(
  topicUrl: string,
  mercureHubUrl: string | null,
  onMessage: (data: T) => void,
  fallbackPoll?: () => Promise<void>,
): SSEStatus {
  const onMessageRef = useRef(onMessage);
  const fallbackPollRef = useRef(fallbackPoll);
  const [connected, setConnected] = useState(false);
  const [disconnected, setDisconnected] = useState(false);
  const [polling, setPolling] = useState(false);
  const [lastMessageAt, setLastMessageAt] = useState<Date | null>(null);

  useEffect(() => { onMessageRef.current = onMessage; }, [onMessage]);
  useEffect(() => { fallbackPollRef.current = fallbackPoll; }, [fallbackPoll]);

  useEffect(() => {
    if (!mercureHubUrl) {
      if (!fallbackPollRef.current) return;
      void fallbackPollRef.current();
      setPolling(true);
      setDisconnected(true);
      const timer = setInterval(() => { void fallbackPollRef.current?.(); }, POLLING_INTERVAL_MS);
      return () => clearInterval(timer);
    }
    const hubUrl = mercureHubUrl;

    let destroyed = false;
    let pollingTimer: ReturnType<typeof setInterval> | null = null;
    let reconnectTimer: ReturnType<typeof setTimeout> | null = null;
    let disconnectTimer: ReturnType<typeof setTimeout> | null = null;
    let es: EventSource | null = null;

    function startPolling(): void {
      if (pollingTimer !== null) return;
      setPolling(true);
      pollingTimer = setInterval(() => { void fallbackPollRef.current?.(); }, POLLING_INTERVAL_MS);
    }

    function stopPolling(): void {
      if (pollingTimer !== null) {
        clearInterval(pollingTimer);
        pollingTimer = null;
      }
      setPolling(false);
    }

    function clearDisconnectTimer(): void {
      if (disconnectTimer !== null) {
        clearTimeout(disconnectTimer);
        disconnectTimer = null;
      }
    }

    function connect(): void {
      if (destroyed) return;

      const url = new URL(hubUrl);
      url.searchParams.append("topic", topicUrl);
      es = new EventSource(url.toString());

      es.onopen = () => {
        clearDisconnectTimer();
        setConnected(true);
        setDisconnected(false);
        stopPolling();
      };

      es.onmessage = (event) => {
        clearDisconnectTimer();
        setConnected(true);
        setDisconnected(false);
        setLastMessageAt(new Date());
        stopPolling();
        try {
          const data = JSON.parse(event.data as string) as T;
          onMessageRef.current(data);
        } catch {
          // Ignore malformed messages
        }
      };

      es.onerror = () => {
        es?.close();
        es = null;
        startPolling();
        // Defer the disconnected state by the grace period to avoid flashing on transient blips
        clearDisconnectTimer();
        disconnectTimer = setTimeout(() => {
          setConnected(false);
          setDisconnected(true);
        }, DISCONNECT_GRACE_MS);
        if (!destroyed) {
          reconnectTimer = setTimeout(() => {
            stopPolling();
            connect();
          }, RECONNECT_DELAY_MS);
        }
      };
    }

    connect();

    return () => {
      destroyed = true;
      es?.close();
      stopPolling();
      clearDisconnectTimer();
      setConnected(false);
      setDisconnected(false);
      setPolling(false);
      if (reconnectTimer !== null) clearTimeout(reconnectTimer);
    };
  }, [topicUrl, mercureHubUrl]);

  return { connected, disconnected, polling, lastMessageAt };
}
