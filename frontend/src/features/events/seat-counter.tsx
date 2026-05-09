"use client";

import { AlertCircle, Loader2, Users, XCircle } from "lucide-react";

export type SeatCounterProps = {
  capacity: number;
  confirmedRegistrations: number;
  loading?: boolean;
  recentlyUpdated?: boolean;
};

type SeatCounterState = "available" | "low" | "full" | "disconnected";

function computeState(
  capacity: number,
  confirmedRegistrations: number,
  loading: boolean,
): SeatCounterState {
  if (loading) return "disconnected";
  const remaining = Math.max(capacity - confirmedRegistrations, 0);
  if (remaining === 0) return "full";
  const lowThreshold = Math.max(3, Math.ceil(capacity * 0.15));
  if (remaining <= lowThreshold) return "low";
  return "available";
}

export function SeatCounter({
  capacity,
  confirmedRegistrations,
  loading = false,
  recentlyUpdated = false,
}: SeatCounterProps) {
  const state = computeState(capacity, confirmedRegistrations, loading);
  const remaining = Math.max(capacity - confirmedRegistrations, 0);

  return (
    <div className="card-glow grid gap-3 rounded-lg border border-border p-5 sm:grid-cols-[auto_1fr] sm:items-start sm:gap-4 sm:p-6">
      <div className="flex size-10 items-center justify-center rounded border border-border bg-background">
        <SeatIcon state={state} />
      </div>
      <div>
        <p className="text-sm text-muted-foreground">Places disponibles</p>
        <div className="mt-0.5 flex items-baseline gap-2">
          <SeatCount
            capacity={capacity}
            recentlyUpdated={recentlyUpdated}
            remaining={remaining}
            state={state}
          />
        </div>
        <SeatLabel state={state} remaining={remaining} />
      </div>
    </div>
  );
}

function SeatIcon({ state }: { state: SeatCounterState }) {
  switch (state) {
    case "full":
      return <XCircle aria-hidden="true" className="size-5 text-danger" />;
    case "low":
      return <AlertCircle aria-hidden="true" className="size-5 text-accent-warm" />;
    case "disconnected":
      return <Loader2 aria-hidden="true" className="size-5 animate-spin text-muted-foreground" />;
    default:
      return <Users aria-hidden="true" className="size-5 text-accent-text" />;
  }
}

function SeatCount({
  state,
  remaining,
  capacity,
  recentlyUpdated,
}: {
  state: SeatCounterState;
  remaining: number;
  capacity: number;
  recentlyUpdated: boolean;
}) {
  const colorClass =
    state === "full"
      ? "text-danger"
      : state === "low"
        ? "text-accent-warm"
        : state === "disconnected"
          ? "text-muted-foreground"
          : "text-foreground";

  if (state === "disconnected") {
    return (
      <p
        aria-atomic="true"
        aria-live="polite"
        className={`font-heading text-2xl font-semibold tabular-nums ${colorClass}`}
      >
        - / {capacity}
      </p>
    );
  }

  if (state === "full") {
    return (
      <p
        aria-atomic="true"
        aria-live="polite"
        className={`font-heading text-2xl font-semibold ${colorClass}`}
      >
        Complet
      </p>
    );
  }

  return (
    <p
      aria-atomic="true"
      aria-live="polite"
      className={`font-heading text-2xl font-semibold tabular-nums transition-colors duration-300 ${
        recentlyUpdated ? "motion-safe:animate-pulse" : ""
      } ${colorClass}`}
    >
      {remaining} / {capacity}
    </p>
  );
}

function SeatLabel({ state, remaining }: { state: SeatCounterState; remaining: number }) {
  if (state === "low") {
    return (
      <p className="mt-1 text-xs font-semibold text-accent-warm">
        Plus que {remaining} place{remaining > 1 ? "s" : ""} !
      </p>
    );
  }
  if (state === "full") {
    return <p className="mt-1 text-xs text-danger">Toutes les places sont reservees.</p>;
  }
  return null;
}
