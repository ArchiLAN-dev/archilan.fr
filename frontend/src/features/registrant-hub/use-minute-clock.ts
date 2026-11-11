import { useSyncExternalStore } from "react";

const MINUTE_MS = 60_000;

function subscribe(onChange: () => void): () => void {
  const id = window.setInterval(onChange, MINUTE_MS);
  return () => window.clearInterval(id);
}

// Read outside render by React's store contract: the minute changes once a minute, so the snapshot stays stable.
function currentMinute(): number {
  return Math.floor(Date.now() / MINUTE_MS);
}

function serverMinute(): number {
  return 0;
}

/**
 * Story 53.1: the current time to the minute, re-rendering once a minute (the hub stays open for a whole LAN).
 * Null on the server: the hub renders its clock-dependent parts on the client only.
 */
export function useMinuteClock(): Date | null {
  const minute = useSyncExternalStore(subscribe, currentMinute, serverMinute);
  return minute === 0 ? null : new Date(minute * MINUTE_MS);
}
