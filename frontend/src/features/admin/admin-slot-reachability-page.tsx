"use client";

import { AlertTriangle, ArrowLeft, Clock, Loader2, RefreshCw, WifiOff } from "lucide-react";
import Link from "next/link";
import { use, useCallback, useEffect, useRef, useState } from "react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { CheckListPanel, ItemListPanel } from "@/features/reachability/check-panels";
import { ItemToast } from "@/features/reachability/item-toast";
import { SphereLine } from "@/features/reachability/sphere-line";
import { StatPill } from "@/features/reachability/check-row";
import type { ReachabilityData, ToastItem } from "@/features/reachability/types";
import { SlotSwitcher } from "./admin-slot-switcher";

type PageState =
  | { kind: "idle" }
  | { kind: "loading" }
  | { kind: "data"; data: ReachabilityData }
  | { kind: "error"; message: string };

export function AdminSlotReachabilityPage({
  params,
}: {
  params: Promise<{ eventId: string; sessionId: string; slotIndex: string }>;
}) {
  const { eventId, sessionId, slotIndex } = use(params);
  const [state, setState] = useState<PageState>({ kind: "idle" });
  const [refreshing, setRefreshing] = useState(false);
  const [liveConnected, setLiveConnected] = useState(false);
  const [showDisconnected, setShowDisconnected] = useState(false);
  const [toastQueue, setToastQueue] = useState<ToastItem[]>([]);

  const refreshingRef = useRef(false);
  const esRef = useRef<EventSource | null>(null);
  const prevItemsRef = useRef<Map<number, number>>(new Map());

  const fetchReachability = useCallback(async (silent = false) => {
    if (silent) {
      if (refreshingRef.current) return;
      refreshingRef.current = true;
      setRefreshing(true);
    } else {
      setState({ kind: "loading" });
    }
    try {
      const res = await apiFetch(
        `${env.apiBaseUrl}/sessions/${sessionId}/slots/${slotIndex}/reachable`,
      );
      if (!res.ok) {
        const body = (await res.json().catch(() => ({}))) as { error?: { message?: string } };
        const msg =
          (typeof body.error === "object" ? body.error?.message : undefined) ??
          `Erreur ${res.status}`;
        if (!silent) setState({ kind: "error", message: msg });
        return;
      }
      const json = (await res.json()) as { data: ReachabilityData };
      setState({ kind: "data", data: json.data });
      if (prevItemsRef.current.size === 0) {
        prevItemsRef.current = new Map(json.data.items_received.map((i) => [i.id, i.count]));
      }
    } catch {
      if (!silent) setState({ kind: "error", message: "Impossible de contacter l'API." });
    } finally {
      refreshingRef.current = false;
      setRefreshing(false);
    }
  }, [sessionId, slotIndex]);

  useEffect(() => {
    void fetchReachability();
  }, [fetchReachability]);

  // SSE subscription — bridge pushes reachability updates directly after each recompute
  useEffect(() => {
    let cancelled = false;
    let reconnectTimer: ReturnType<typeof setTimeout> | null = null;

    function connect(token: string, hubUrl: string, topic: string): void {
      if (cancelled) return;
      const url = new URL(hubUrl);
      url.searchParams.set("topic", topic);
      url.searchParams.set("authorization", token);
      const es = new EventSource(url.toString());
      esRef.current = es;

      es.onopen = () => { if (!cancelled) setLiveConnected(true); };

      es.onmessage = (event) => {
        try {
          const data = JSON.parse(event.data as string) as ReachabilityData;
          if (!data.counts) return;

          if (prevItemsRef.current.size === 0) {
            prevItemsRef.current = new Map(data.items_received.map((i) => [i.id, i.count]));
          } else {
            const itemFlagsMap = new Map<number, number>(
              [...data.reachable_unchecked, ...data.reachable_checked, ...data.unreachable_unchecked]
                .filter((c) => c.item != null)
                .map((c) => [c.item!.id, c.item!.flags])
            );
            const newItems: { name: string; flags: number }[] = [];
            for (const item of data.items_received) {
              const prevCount = prevItemsRef.current.get(item.id) ?? 0;
              for (let k = 0; k < item.count - prevCount; k++) {
                newItems.push({ name: item.name, flags: itemFlagsMap.get(item.id) ?? 0 });
              }
            }
            prevItemsRef.current = new Map(data.items_received.map((i) => [i.id, i.count]));
            if (newItems.length > 0) {
              const base = Date.now();
              setToastQueue((prev) => [
                ...prev,
                ...newItems.map((item, i) => ({ id: base + i, name: item.name, flags: item.flags })),
              ]);
            }
          }

          setState({ kind: "data", data });
        } catch { /* ignore malformed SSE frames */ }
      };

      es.onerror = () => {
        es.close();
        esRef.current = null;
        if (!cancelled) {
          setLiveConnected(false);
          reconnectTimer = setTimeout(() => { connect(token, hubUrl, topic); }, 5_000);
        }
      };
    }

    async function init(): Promise<void> {
      const tokenRes = await apiFetch(
        `${env.apiBaseUrl}/sessions/${sessionId}/slots/${slotIndex}/reachable-token`,
      );
      if (!tokenRes.ok || cancelled) return;
      const tokenJson = (await tokenRes.json()) as {
        data: { token: string; hubUrl: string; topic: string };
      };
      const { token, hubUrl, topic } = tokenJson.data;
      if (!hubUrl || cancelled) return;
      connect(token, hubUrl, topic);
    }

    void init().catch(() => undefined);

    return () => {
      cancelled = true;
      esRef.current?.close();
      esRef.current = null;
      if (reconnectTimer) clearTimeout(reconnectTimer);
    };
  }, [sessionId, slotIndex]);

  useEffect(() => {
    const id = setTimeout(() => { setShowDisconnected(!liveConnected); }, liveConnected ? 0 : 3_000);
    return () => { clearTimeout(id); };
  }, [liveConnected]);

  const backHref = `/admin/evenements/${eventId}/session/${sessionId}?tab=slots`;

  return (
    <>
      {toastQueue.length > 0 ? (
        <ItemToast
          flags={toastQueue[0].flags}
          itemName={toastQueue[0].name}
          key={toastQueue[0].id}
          onDone={() => setToastQueue((prev) => prev.slice(1))}
        />
      ) : null}

      <section className="grid w-full gap-8 px-4 py-10">
        <header className="grid gap-3">
          <p className="text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">
            Backoffice · Sessions · Slot {slotIndex}
          </p>
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <div className="flex items-center gap-3">
                <h1 className="font-heading text-4xl font-bold leading-tight text-foreground">
                  {state.kind === "data" ? state.data.player : `Slot ${slotIndex}`}
                </h1>
                {showDisconnected ? (
                  <span className="inline-flex items-center gap-1.5 text-xs text-accent-warm">
                    <WifiOff aria-hidden="true" className="size-3.5" />
                    Reconnexion…
                  </span>
                ) : liveConnected ? (
                  <span className="inline-flex items-center gap-1.5 text-xs text-success">
                    <span aria-hidden="true" className="size-2 animate-pulse rounded-full bg-success" />
                    Live
                  </span>
                ) : null}
              </div>
              {state.kind === "data" ? (
                <p className="mt-1 font-mono text-sm text-muted-foreground">{state.data.game}</p>
              ) : null}
            </div>
            <SlotSwitcher currentSlot={slotIndex} eventId={eventId} sessionId={sessionId} />
          </div>
          <nav className="flex items-center gap-2 text-sm text-muted-foreground">
            <Link className="inline-flex items-center gap-1 hover:text-foreground" href={backHref}>
              <ArrowLeft aria-hidden="true" className="size-3.5" />
              Retour à la progression
            </Link>
          </nav>
        </header>

        {state.kind === "loading" ? (
          <div className="grid gap-6">
            <div className="flex items-center gap-3 rounded border border-border bg-surface p-6 text-sm text-muted-foreground">
              <Loader2 aria-hidden="true" className="size-5 animate-spin text-accent-text" />
              <span>Calcul de la réatteignabilité en cours… (jusqu&apos;à ~20 secondes)</span>
            </div>
            <div className="grid gap-4 sm:grid-cols-3">
              {[0, 1, 2].map((i) => (
                <div key={i} className="rounded border border-border bg-surface p-4">
                  <div className="mb-3 h-4 w-28 animate-pulse rounded bg-surface-2" />
                  <div className="grid gap-2">
                    {[0, 1, 2].map((j) => (
                      <div key={j} className="h-3 animate-pulse rounded bg-surface-2" style={{ width: `${60 + j * 15}%` }} />
                    ))}
                  </div>
                </div>
              ))}
            </div>
          </div>
        ) : null}

        {state.kind === "error" ? (
          <div className="grid gap-4">
            <div className="flex items-start gap-3 rounded border border-danger/40 bg-danger/5 p-4 text-sm text-danger">
              <AlertTriangle aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
              {state.message}
            </div>
            <button
              className="inline-flex w-fit items-center gap-2 rounded border border-border bg-surface px-4 py-2 text-sm font-semibold text-foreground hover:border-accent"
              onClick={() => { void fetchReachability(); }}
              type="button"
            >
              <RefreshCw aria-hidden="true" className="size-4" />
              Réessayer
            </button>
          </div>
        ) : null}

        {state.kind === "data" ? (
          <div className="grid gap-6">
            <div className="flex flex-wrap items-center gap-3">
              <StatPill label="Checks faits" value={`${state.data.counts.checked} / ${state.data.counts.total}`} />
              <StatPill highlight label="Faisables maintenant" value={String(state.data.counts.reachable_now)} />
              <StatPill label="Items reçus" value={String(state.data.items_received.length)} />
              {state.data.cached ? (
                <span className="inline-flex items-center gap-1 rounded border border-border px-2 py-0.5 text-xs text-muted-foreground">
                  <Clock aria-hidden="true" className="size-3" />
                  Résultat mis en cache
                </span>
              ) : null}
              {refreshing ? (
                <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                  <Loader2 aria-hidden="true" className="size-3.5 animate-spin" />
                  Actualisation…
                </span>
              ) : null}
              <button
                className="ml-auto inline-flex items-center gap-1.5 rounded border border-border bg-surface px-3 py-1.5 text-xs font-semibold text-foreground hover:border-accent disabled:opacity-50"
                disabled={refreshing}
                onClick={() => { void fetchReachability(); }}
                type="button"
              >
                <RefreshCw aria-hidden="true" className={`size-3.5 ${refreshing ? "animate-spin" : ""}`} />
                Recalculer
              </button>
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
              <ItemListPanel
                emptyMessage="Aucun item reçu."
                items={state.data.items_received}
                title="Items reçus"
                variant="received"
              />
              <ItemListPanel
                emptyMessage="Tous les items ont été reçus !"
                items={state.data.items_not_received ?? []}
                title="Items non reçus"
                variant="not-received"
              />
              <CheckListPanel
                checks={state.data.reachable_unchecked}
                currentSlot={Number(slotIndex)}
                emptyMessage="Aucun check faisable avec les items actuels."
                title="Checks faisables maintenant"
                variant="reachable"
              />
              <CheckListPanel
                checks={state.data.unreachable_unchecked}
                currentSlot={Number(slotIndex)}
                emptyMessage="Tous les checks sont faisables !"
                title="Checks non faisables"
                variant="unreachable"
              />
            </div>

            {state.data.spheres && state.data.spheres.length > 0 ? (
              <SphereLine currentSlot={Number(slotIndex)} spheres={state.data.spheres} />
            ) : null}
          </div>
        ) : null}
      </section>
    </>
  );
}
