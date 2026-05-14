"use client";

import { AlertTriangle, ChevronRight, Trophy, WifiOff } from "lucide-react";
import Link from "next/link";
import { useEffect, useRef, useState } from "react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

// ─── Types ───────────────────────────────────────────────────────────────────

type SlotData = {
  slot_name: string;
  checks_done: number;
  checks_total: number;
  items_received: number;
  client_status: number;
  goal_reached_at: string | null;
  reachable_now: number | null;
};

type SlotsMap = Record<string, SlotData>;

type GridState =
  | { kind: "loading" }
  | { kind: "unavailable" }
  | { kind: "active"; slots: SlotsMap; connected: boolean };

// ─── Status config ────────────────────────────────────────────────────────────

const STATUS_LABELS: Record<number, string> = {
  0: "Hors ligne",
  5: "Connecté",
  10: "Prêt",
  20: "En jeu",
  30: "Objectif atteint !",
};

const STATUS_CLASSES: Record<number, string> = {
  0: "bg-muted text-muted-foreground",
  5: "bg-gray-400 text-white",
  10: "bg-yellow-500 text-white",
  20: "bg-blue-500 text-white",
  30: "bg-green-500 text-white",
};

// ─── Sorting ──────────────────────────────────────────────────────────────────

function sortedEntries(slots: SlotsMap): [string, SlotData][] {
  return Object.entries(slots).sort(([, a], [, b]) => {
    const bucket = (s: SlotData) => (s.client_status === 30 ? 0 : s.client_status === 20 ? 1 : 2);
    const ba = bucket(a);
    const bb = bucket(b);
    if (ba !== bb) return ba - bb;
    if (ba === 0) {
      return goalReachedTime(a.goal_reached_at) - goalReachedTime(b.goal_reached_at);
    }
    return b.checks_done - a.checks_done;
  });
}

function goalReachedTime(value: string | null): number {
  if (!value) return Infinity;

  const timestamp = new Date(value).getTime();
  return Number.isFinite(timestamp) ? timestamp : Infinity;
}

function clampPercent(value: number): number {
  return Math.max(0, Math.min(100, value));
}

// ─── Component ───────────────────────────────────────────────────────────────

export function PlayerProgressGrid({
  runId,
  eventId,
  personalRunId,
}: {
  runId: string;
  eventId?: string;
  personalRunId?: string;
}) {
  const [state, setState] = useState<GridState>({ kind: "loading" });
  const [showReconnect, setShowReconnect] = useState(false);
  const esRef = useRef<EventSource | null>(null);
  const reconnectTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    let cancelled = false;

    function connect(token: string, hubUrl: string, topic: string): void {
      if (cancelled || !hubUrl) return;

      const url = new URL(hubUrl);
      url.searchParams.set("topic", topic);
      url.searchParams.set("authorization", token);
      const es = new EventSource(url.toString());
      esRef.current = es;

      es.onopen = () => {
        setState((prev) =>
          prev.kind === "active" ? { ...prev, connected: true } : prev,
        );
      };

      es.onmessage = (event) => {
        try {
          const data = JSON.parse(event.data as string) as { slots?: SlotsMap };
          if (data.slots) {
            setState((prev) =>
              prev.kind === "active"
                ? { ...prev, slots: data.slots as SlotsMap, connected: true }
                : prev,
            );
          }
        } catch {
          /* ignore malformed */
        }
      };

      es.onerror = () => {
        es.close();
        esRef.current = null;
        setState((prev) =>
          prev.kind === "active" ? { ...prev, connected: false } : prev,
        );
        if (!cancelled) {
          reconnectTimerRef.current = setTimeout(
            () => { connect(token, hubUrl, topic); },
            5_000,
          );
        }
      };
    }

    async function init(): Promise<void> {
      // 1. Fetch initial state
      const stateRes = await apiFetch(
        `${env.apiBaseUrl}/sessions/${runId}/players`,
      );

      if (cancelled) return;

      let initialSlots: SlotsMap = {};
      if (stateRes.ok) {
        const json = (await stateRes.json()) as { data?: { slots?: SlotsMap } };
        initialSlots = json.data?.slots ?? {};
      }

      // 2. Fetch subscriber token
      const tokenRes = await apiFetch(
        `${env.apiBaseUrl}/sessions/${runId}/players-token`,
      );

      if (cancelled) return;

      if (!tokenRes.ok) {
        setState({ kind: "unavailable" });
        return;
      }

      const tokenJson = (await tokenRes.json()) as {
        data: { token: string; hubUrl: string; topic: string };
      };
      const { token, hubUrl, topic } = tokenJson.data;

      if (cancelled || !hubUrl) {
        setState({ kind: "unavailable" });
        return;
      }

      setState({ kind: "active", slots: initialSlots, connected: false });
      connect(token, hubUrl, topic);
    }

    void init().catch(() => {
      if (!cancelled) setState({ kind: "unavailable" });
    });

    return () => {
      cancelled = true;
      esRef.current?.close();
      esRef.current = null;
      if (reconnectTimerRef.current) clearTimeout(reconnectTimerRef.current);
    };
  }, [runId]);

  const disconnected = state.kind === "active" && !state.connected;

  useEffect(() => {
    const id = setTimeout(() => {
      setShowReconnect(disconnected);
    }, disconnected ? 3_000 : 0);

    return () => {
      clearTimeout(id);
    };
  }, [disconnected]);

  if (state.kind === "loading") {
    return (
      <section className="grid gap-3">
        <div className="h-6 w-48 animate-pulse rounded bg-surface-2" aria-hidden="true" />
        <span className="sr-only">Chargement des joueurs…</span>
        <div aria-hidden="true" className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {[0, 1, 2].map((i) => (
            <div key={i} className="rounded border border-border bg-surface p-4 grid gap-3">
              <div className="flex items-start justify-between gap-2">
                <div className="h-4 w-28 animate-pulse rounded bg-surface-2" />
                <div className="h-4 w-16 animate-pulse rounded-full bg-surface-2" />
              </div>
              <div className="h-2 w-full animate-pulse rounded-full bg-surface-2" />
              <div className="flex gap-4">
                <div className="h-3 w-12 animate-pulse rounded bg-surface-2" />
                <div className="h-3 w-12 animate-pulse rounded bg-surface-2" />
              </div>
            </div>
          ))}
        </div>
      </section>
    );
  }

  if (state.kind === "unavailable") {
    return null;
  }

  const { slots } = state;
  const entries = sortedEntries(slots);
  const goalCount = entries.filter(([, s]) => s.client_status === 30).length;
  const total = entries.length;
  const globalPct = total > 0 ? clampPercent(Math.round((goalCount / total) * 100)) : 0;

  return (
    <section className="grid gap-3">
      <div className="flex items-center justify-between gap-3">
        <h2 className="font-heading text-xl font-semibold text-foreground">
          Progression des joueurs
        </h2>
        {showReconnect ? (
          <span className="inline-flex items-center gap-1.5 text-xs text-accent-warm">
            <WifiOff aria-hidden="true" className="size-3.5" />
            Reconnexion en cours…
          </span>
        ) : null}
      </div>

      {total > 0 ? (
        <div className="grid gap-1.5">
          <p className="text-sm font-semibold text-foreground">
            {goalCount}/{total} objectifs atteints
          </p>
          <div className="h-1.5 w-full overflow-hidden rounded-full bg-surface-2">
            <div
              className="h-full rounded-full bg-success transition-[width] duration-300"
              style={{ width: `${globalPct}%` }}
            />
          </div>
        </div>
      ) : null}

      {entries.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          Aucune donnée joueur disponible pour l&apos;instant.
        </p>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {entries.map(([slotIndex, slot]) => (
            <SlotCard
              key={slotIndex}
              href={
                eventId
                  ? `/admin/evenements/${eventId}/session/${runId}/slots/${slotIndex}`
                  : personalRunId
                    ? `/runs/${personalRunId}/progression/${slotIndex}`
                    : undefined
              }
              slot={slot}
            />
          ))}
        </div>
      )}
    </section>
  );
}

// ─── SlotCard ────────────────────────────────────────────────────────────────

function SlotCard({ slot, href }: { slot: SlotData; href?: string }) {
  const isGoal = slot.client_status === 30;
  const isPlaying = slot.client_status === 20;
  const isBK =
    !isGoal &&
    slot.reachable_now !== null &&
    slot.reachable_now === 0 &&
    slot.checks_done < slot.checks_total;
  const statusLabel = STATUS_LABELS[slot.client_status] ?? String(slot.client_status);
  const statusCls = STATUS_CLASSES[slot.client_status] ?? "bg-muted text-muted-foreground";
  const progressPct =
    slot.checks_total > 0
      ? clampPercent(Math.round((slot.checks_done / slot.checks_total) * 100))
      : 0;

  const goalTime = isGoal && slot.goal_reached_at
    ? new Intl.DateTimeFormat("fr-FR", { hour: "2-digit", minute: "2-digit" }).format(new Date(slot.goal_reached_at))
    : null;

  const cardCls = `grid gap-3 rounded border p-4 ${
    href ? "transition-colors hover:border-accent-text/50" : ""
  } ${
    isGoal
      ? "border-success bg-success/5 ring-2 ring-success/30"
      : isBK
        ? "border-danger/50 bg-danger/5 ring-2 ring-danger/20"
        : "border-border bg-surface"
  }`;

  const inner = (
    <>
      {/* Header */}
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="truncate font-mono text-sm font-semibold text-foreground">
            {slot.slot_name}
          </p>
          {goalTime ? (
            <time className="mt-0.5 block text-xs text-muted-foreground">{goalTime}</time>
          ) : null}
        </div>
        <div className="flex shrink-0 items-center gap-1">
          {isGoal ? <Trophy aria-hidden="true" className="size-5 text-success" /> : null}
          {isBK ? <AlertTriangle aria-hidden="true" className="size-4 text-danger" /> : null}
          {href ? <ChevronRight aria-hidden="true" className="size-4 text-muted-foreground/50" /> : null}
        </div>
      </div>

      {/* Status badge + BK badge */}
      <div className="flex flex-wrap items-center gap-1.5">
        <span
          className={`inline-flex w-fit items-center gap-1.5 rounded px-2 py-0.5 text-xs font-semibold ${statusCls}`}
        >
          {isPlaying ? (
            <span className="size-2 animate-pulse rounded-full bg-blue-500 shrink-0" aria-hidden="true" />
          ) : null}
          {statusLabel}
        </span>
        {isBK ? (
          <span className="inline-flex items-center gap-1 rounded border border-danger/40 bg-danger/10 px-2 py-0.5 text-xs font-bold text-danger">
            BK
          </span>
        ) : null}
      </div>

      {/* Progress bar */}
      <div className="grid gap-1">
        <div className="flex justify-between text-xs text-muted-foreground">
          <span>Checks</span>
          <span>
            {slot.checks_done} / {slot.checks_total}
          </span>
        </div>
        <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
          <div
            className={`h-full rounded-full transition-[width] duration-500 ease-in-out ${isGoal ? "bg-success" : isBK ? "bg-danger/60" : "bg-accent"}`}
            style={{ width: `${progressPct}%` }}
          />
        </div>
      </div>

      {/* Footer: items reçus + checks accessibles */}
      <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
        <span>
          Items reçus :{" "}
          <span className="font-semibold text-foreground">{slot.items_received}</span>
        </span>
        {typeof slot.reachable_now === "number" ? (
          <span
            className={`font-semibold ${
              isBK ? "text-danger" : slot.reachable_now > 0 ? "text-success" : "text-muted-foreground"
            }`}
          >
            {slot.reachable_now} check{slot.reachable_now !== 1 ? "s" : ""} accessibles
          </span>
        ) : (
          <span className="text-muted-foreground/50">-</span>
        )}
      </div>
    </>
  );

  if (href) {
    return <Link className={cardCls} href={href}>{inner}</Link>;
  }
  return <div className={cardCls}>{inner}</div>;
}
