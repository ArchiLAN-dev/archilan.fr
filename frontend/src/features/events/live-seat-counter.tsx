"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { useSSE } from "@/hooks/use-sse";
import { SeatCounter } from "./seat-counter";

type SeatCounterMessage = {
  eventId: string;
  remainingSeats: number;
};

// Guard for seat-counter Mercure frames (story 33.19): the api is the single publisher of this
// topic, so checking the two required fields is sufficient.
function isSeatCounterMessage(v: unknown): v is SeatCounterMessage {
  if (typeof v !== "object" || v === null) return false;
  if (!("eventId" in v) || typeof v.eventId !== "string") return false;
  return "remainingSeats" in v && typeof v.remainingSeats === "number";
}

type LiveSeatCounterProps = {
  eventId: string;
  initialCapacity: number;
  initialConfirmedRegistrations: number;
};

const STALE_THRESHOLD_MS = 120_000;
const UPDATE_ANIMATION_MS = 900;

export function LiveSeatCounter({
  eventId,
  initialCapacity,
  initialConfirmedRegistrations,
}: LiveSeatCounterProps) {
  const [confirmedRegistrations, setConfirmedRegistrations] = useState(
    initialConfirmedRegistrations,
  );
  const [lastUpdatedAt, setLastUpdatedAt] = useState<Date | null>(null);
  const [isStale, setIsStale] = useState(false);
  const [recentlyUpdated, setRecentlyUpdated] = useState(false);
  const updateAnimationTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const topicUrl = `https://archilan.fr/events/${eventId}/seat-counter`;
  const mercureHubUrl = env.mercurePublicUrl !== "" ? env.mercurePublicUrl : null;

  const applyRemainingSeats = useCallback(
    (remainingSeats: number) => {
      const normalizedRemainingSeats = Math.min(Math.max(remainingSeats, 0), initialCapacity);
      setConfirmedRegistrations(initialCapacity - normalizedRemainingSeats);
      setLastUpdatedAt(new Date());
      setRecentlyUpdated(true);
      if (updateAnimationTimerRef.current !== null) {
        clearTimeout(updateAnimationTimerRef.current);
      }
      updateAnimationTimerRef.current = setTimeout(() => {
        setRecentlyUpdated(false);
        updateAnimationTimerRef.current = null;
      }, UPDATE_ANIMATION_MS);
    },
    [initialCapacity],
  );

  const onMessage = useCallback(
    (data: SeatCounterMessage) => {
      if (data.eventId !== eventId) return;
      applyRemainingSeats(data.remainingSeats);
    },
    [applyRemainingSeats, eventId],
  );

  const fallbackPoll = useCallback(async () => {
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/events/${eventId}`);
      if (!res.ok) return;
      const payload: unknown = await res.json();
      if (typeof payload !== "object" || payload === null || !("data" in payload)) return;
      const data: unknown = payload.data;
      if (
        typeof data === "object" &&
        data !== null &&
        "confirmedRegistrations" in data &&
        typeof data.confirmedRegistrations === "number"
      ) {
        applyRemainingSeats(initialCapacity - data.confirmedRegistrations);
      }
    } catch {
      // Keep current count when polling fails.
    }
  }, [applyRemainingSeats, eventId, initialCapacity]);

  const { connected, disconnected, polling } = useSSE(
    topicUrl,
    mercureHubUrl,
    isSeatCounterMessage,
    onMessage,
    fallbackPoll,
  );

  useEffect(() => {
    if (!lastUpdatedAt) return;
    const resetTimer = setTimeout(() => { setIsStale(false); }, 0);
    const timer = setInterval(() => {
      setIsStale(Date.now() - lastUpdatedAt.getTime() > STALE_THRESHOLD_MS);
    }, 30_000);
    return () => {
      clearTimeout(resetTimer);
      clearInterval(timer);
    };
  }, [lastUpdatedAt]);

  useEffect(() => {
    if (connected) {
      const resetTimer = setTimeout(() => { setIsStale(false); }, 0);
      return () => clearTimeout(resetTimer);
    }

    return undefined;
  }, [connected]);

  useEffect(() => {
    return () => {
      if (updateAnimationTimerRef.current !== null) {
        clearTimeout(updateAnimationTimerRef.current);
      }
    };
  }, []);

  const showDisconnected = mercureHubUrl !== null && disconnected && !isStale;

  return (
    <div>
      <SeatCounter
        capacity={initialCapacity}
        confirmedRegistrations={confirmedRegistrations}
        recentlyUpdated={recentlyUpdated}
      />

      {isStale ? (
        <div className="mt-2 flex items-center justify-between gap-3 rounded border border-accent-warm/30 bg-accent-warm/5 px-3 py-1.5">
          <p className="text-xs text-accent-warm">Donnees peut-etre obsoletes</p>
          <button
            className="text-xs font-semibold text-accent-warm hover:underline"
            onClick={() => { void fallbackPoll(); }}
            type="button"
          >
            Actualiser
          </button>
        </div>
      ) : null}

      {showDisconnected ? (
        <p className="mt-2 text-xs text-muted-foreground">
          Reconnexion en cours{polling ? ", actualisation automatique active" : ""}...
        </p>
      ) : null}
    </div>
  );
}
