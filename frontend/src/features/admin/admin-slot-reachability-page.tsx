"use client";

import { AlertTriangle, ArrowLeft, Clock, Download, Gift, Lightbulb, ListChecks, Loader2, MapPin, Package, RefreshCw, Route, SendHorizontal, ShieldCheck, ShieldOff, Tag, Zap, WifiOff } from "lucide-react";
import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { type ElementType, use, useCallback, useEffect, useLayoutEffect, useRef, useState } from "react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { useAuth } from "@/features/auth/auth-context";
import { CheckListPanel, ItemListPanel } from "@/features/reachability/check-panels";
import { GoalCelebration } from "@/features/reachability/goal-celebration";
import { HintsPanel } from "@/features/reachability/hints-panel";
import { ItemToast } from "@/features/reachability/item-toast";
import { SphereLine } from "@/features/reachability/sphere-line";
import type { HintsData, ItemLocation, ReachabilityData, ToastItem } from "@/features/reachability/types";
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
  const { user } = useAuth();
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();

  type TabId = "checks" | "spheres" | "indices" | "items" | "action";
  const TABS: { id: TabId; label: string; icon: ElementType }[] = [
    { id: "checks",  label: "Checks",  icon: ListChecks },
    { id: "items",   label: "Items",   icon: Package },
    { id: "spheres", label: "Sphères", icon: Route },
    { id: "indices", label: "Indices", icon: Lightbulb },
    { id: "action",  label: "Actions", icon: Zap },
  ];
  const VALID_TABS = new Set<string>(TABS.map((t) => t.id));
  const rawTab = searchParams.get("tab");
  const activeTab: TabId = VALID_TABS.has(rawTab ?? "") ? (rawTab as TabId) : "checks";

  function switchTab(tab: TabId) {
    const p = new URLSearchParams(searchParams.toString());
    if (tab === "checks") {
      p.delete("tab");
    } else {
      p.set("tab", tab);
    }
    const qs = p.toString();
    router.replace(`${pathname}${qs ? `?${qs}` : ""}`, { scroll: false });
  }

  const [state, setState] = useState<PageState>({ kind: "idle" });
  const [refreshing, setRefreshing] = useState(false);
  const [liveConnected, setLiveConnected] = useState(false);
  const [showDisconnected, setShowDisconnected] = useState(false);
  const [toastQueue, setToastQueue] = useState<ToastItem[]>([]);
  const [hints, setHints] = useState<HintsData | null>(null);
  const [hintFree, setHintFree] = useState(false);
  const [itemSearch, setItemSearch] = useState("");
  const [itemSuggestOpen, setItemSuggestOpen] = useState(false);
  const [itemQty, setItemQty] = useState(1);
  const [locationSearch, setLocationSearch] = useState("");
  const [locationSuggestOpen, setLocationSuggestOpen] = useState(false);
  const [aliasInput, setAliasInput] = useState("");
  const [actionLoading, setActionLoading] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [goalInfo, setGoalInfo] = useState<{ slotName: string; playerAlias?: string; gameName: string; checksPercent: number; itemsPercent: number } | null>(null);
  const [goalReached, setGoalReached] = useState(false);
  const goalShownRef = useRef(false);
  const stateRef = useRef(state);
  // eslint-disable-next-line react-hooks/refs
  stateRef.current = state;

  const refreshingRef = useRef(false);
  const [itemLocations, setItemLocations] = useState<Record<number, ItemLocation[]>>({});

  const esRef = useRef<EventSource | null>(null);
  const hintsEsRef = useRef<EventSource | null>(null);
  const prevItemsRef = useRef<Map<number, number>>(new Map());
  const fetchReachabilityRef = useRef<(silent?: boolean) => Promise<void>>(() => Promise.resolve());

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

  useLayoutEffect(() => {
    fetchReachabilityRef.current = fetchReachability;
  });

  useEffect(() => {
    void fetchReachabilityRef.current();
  }, [sessionId, slotIndex]);

  // Fetch hints once on mount
  useEffect(() => {
    async function fetchHints(): Promise<void> {
      try {
        const res = await apiFetch(
          `${env.apiBaseUrl}/sessions/${sessionId}/slots/${slotIndex}/hints`,
        );
        if (!res.ok) return;
        const json = (await res.json()) as { data: HintsData };
        setHints(json.data);
      } catch { /* non-critical */ }
    }
    void fetchHints();
  }, [sessionId, slotIndex]);

  // Fetch cross-game item source locations (items received from other games)
  useEffect(() => {
    const currentSlotNum = Number(slotIndex);
    async function fetchItemLocations(): Promise<void> {
      try {
        const res = await apiFetch(
          `${env.apiBaseUrl}/sessions/${sessionId}/slots/${slotIndex}/item-locations`,
        );
        if (!res.ok) return;
        const json = (await res.json()) as { data: { locations: Array<{ itemId: number; locationName: string; findingPlayer: number; findingPlayerName: string; checkStatus: string }> } };
        const map: Record<number, ItemLocation[]> = {};
        for (const loc of json.data.locations) {
          const locs = map[loc.itemId] ?? [];
          locs.push({
            locationName: loc.locationName,
            gameName: loc.findingPlayer === currentSlotNum ? null : loc.findingPlayerName,
            checkStatus: (loc.checkStatus as ItemLocation["checkStatus"]) ?? null,
          });
          map[loc.itemId] = locs;
        }
        setItemLocations(map);
      } catch { /* non-critical */ }
    }
    void fetchItemLocations();
  }, [sessionId, slotIndex]);

  // SSE subscription - bridge pushes reachability updates directly after each recompute
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

  // SSE subscription for hints
  useEffect(() => {
    let cancelled = false;
    let reconnectTimer: ReturnType<typeof setTimeout> | null = null;

    function connectHints(token: string, hubUrl: string, topic: string): void {
      if (cancelled) return;
      const url = new URL(hubUrl);
      url.searchParams.set("topic", topic);
      url.searchParams.set("authorization", token);
      const es = new EventSource(url.toString());
      hintsEsRef.current = es;

      es.onmessage = (event) => {
        try {
          const data = JSON.parse(event.data as string) as Partial<HintsData>;
          if ("hints" in data) {
            setHints((prev) => (prev ? { ...prev, ...data } as HintsData : data as HintsData));
          }
        } catch { /* ignore */ }
      };

      es.onerror = () => {
        es.close();
        hintsEsRef.current = null;
        if (!cancelled) {
          reconnectTimer = setTimeout(() => { connectHints(token, hubUrl, topic); }, 5_000);
        }
      };
    }

    async function initHints(): Promise<void> {
      const tokenRes = await apiFetch(
        `${env.apiBaseUrl}/sessions/${sessionId}/slots/${slotIndex}/hints-token`,
      );
      if (!tokenRes.ok || cancelled) return;
      const tokenJson = (await tokenRes.json()) as {
        data: { token: string; hubUrl: string; topic: string };
      };
      const { token, hubUrl, topic } = tokenJson.data;
      if (!hubUrl || cancelled) return;
      connectHints(token, hubUrl, topic);
    }

    void initHints().catch(() => undefined);

    return () => {
      cancelled = true;
      hintsEsRef.current?.close();
      hintsEsRef.current = null;
      if (reconnectTimer) clearTimeout(reconnectTimer);
    };
  }, [sessionId, slotIndex]);

  // SSE subscription for players state - goal detection
  useEffect(() => {
    let cancelled = false;
    let reconnectTimer: ReturnType<typeof setTimeout> | null = null;
    const slotKey = String(slotIndex);

    function connectPlayers(token: string, hubUrl: string, topic: string): void {
      if (cancelled) return;
      const url = new URL(hubUrl);
      url.searchParams.set("topic", topic);
      url.searchParams.set("authorization", token);
      const es = new EventSource(url.toString());

      es.onmessage = (event) => {
        try {
          const data = JSON.parse(event.data as string) as { slots?: Record<string, { client_status?: number; goal_reached_at?: string | null }> };
          const slot = data.slots?.[slotKey];
          if (slot?.client_status === 30 && slot.goal_reached_at) {
            setGoalReached(true);
          }
          if (slot?.client_status === 30 && slot.goal_reached_at && !goalShownRef.current) {
            goalShownRef.current = true;
            const cur = stateRef.current;
            const d = cur.kind === "data" ? cur.data : null;
            const checksPercent = d ? Math.round((d.counts.checked / Math.max(1, d.counts.total)) * 100) : 0;
            const itemsTotal = d ? d.items_received.length + d.items_not_received.length : 0;
            const itemsPercent = d && itemsTotal > 0 ? Math.round((d.items_received.length / itemsTotal) * 100) : 0;
            setGoalInfo({
              slotName: d?.player ?? `Slot ${slotIndex}`,
              playerAlias: user?.displayName ?? undefined,
              gameName: d?.game ?? "",
              checksPercent,
              itemsPercent,
            });
          }
        } catch { /* ignore */ }
      };

      es.onerror = () => {
        es.close();
        if (!cancelled) {
          reconnectTimer = setTimeout(() => { connectPlayers(token, hubUrl, topic); }, 5_000);
        }
      };
    }

    async function initPlayers(): Promise<void> {
      // Check current state first - goal may have been reached before this page was opened
      const stateRes = await apiFetch(`${env.apiBaseUrl}/sessions/${sessionId}/players`).catch(() => null);
      if (stateRes?.ok && !cancelled) {
        const stateJson = (await stateRes.json()) as { data: { slots?: Record<string, { client_status?: number; goal_reached_at?: string | null }> } };
        const slot = stateJson.data.slots?.[slotKey];
        if (slot?.client_status === 30 && slot.goal_reached_at) {
          setGoalReached(true);
        }
      }

      const tokenRes = await apiFetch(`${env.apiBaseUrl}/sessions/${sessionId}/players-token`);
      if (!tokenRes.ok || cancelled) return;
      const tokenJson = (await tokenRes.json()) as { data: { token: string; hubUrl: string; topic: string } };
      const { token, hubUrl, topic } = tokenJson.data;
      if (!hubUrl || cancelled) return;
      connectPlayers(token, hubUrl, topic);
    }

    void initPlayers().catch(() => undefined);

    return () => {
      cancelled = true;
      if (reconnectTimer) clearTimeout(reconnectTimer);
    };
  }, [sessionId, slotIndex]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    const id = setTimeout(() => { setShowDisconnected(!liveConnected); }, liveConnected ? 0 : 3_000);
    return () => { clearTimeout(id); };
  }, [liveConnected]);

  async function handleHintLocation(locationId: number): Promise<void> {
    const res = await apiFetch(
      `${env.apiBaseUrl}/sessions/${sessionId}/slots/${slotIndex}/hints/request`,
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ location_id: locationId, free: hintFree }),
      },
    );
    if (!res.ok) throw new Error(`hint location failed: ${res.status}`);
  }

  async function handleHintItem(itemName: string): Promise<void> {
    // free → admin command !hint (no point cost); paid → player command /hint (costs bridge slot points)
    const command = hintFree ? `!hint ${itemName}` : `/hint ${itemName}`;
    const res = await apiFetch(
      `${env.apiBaseUrl}/admin/sessions/${sessionId}/commands`,
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ command }),
      },
    );
    if (!res.ok) throw new Error(`hint item failed: ${res.status}`);
  }

  async function sendBridgeCommand(command: string, actionId: string): Promise<void> {
    setActionLoading(actionId);
    setActionError(null);
    try {
      const res = await apiFetch(
        `${env.apiBaseUrl}/admin/sessions/${sessionId}/commands`,
        { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ command }) },
      );
      if (!res.ok) {
        const body = await res.json().catch(() => ({})) as { error?: string; message?: string };
        throw new Error((body as Record<string, string>).error ?? (body as Record<string, string>).message ?? `Erreur ${res.status}`);
      }
    } catch (err) {
      setActionError(err instanceof Error ? err.message : "Erreur inconnue");
    } finally {
      setActionLoading(null);
    }
  }

  const backHref = `/admin/evenements/${eventId}/session/${sessionId}?tab=slots`;

  return (
    <>
      {goalInfo ? (
        <GoalCelebration
          checksPercent={goalInfo.checksPercent}
          gameName={goalInfo.gameName}
          itemsPercent={goalInfo.itemsPercent}
          onDismiss={() => { setGoalInfo(null); }}
          playerAlias={goalInfo.playerAlias}
          slotName={goalInfo.slotName}
        />
      ) : null}

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

        {/* Tab bar - always visible once the section is rendered */}
        <div className="-mx-4 flex items-stretch gap-0 border-b border-border bg-surface px-4">
          {TABS.map((tab) => {
            const data = state.kind === "data" ? state.data : null;
            const sub =
              tab.id === "checks" && data
                ? { main: `${data.counts.checked} / ${data.counts.total}`, highlight: data.counts.reachable_now > 0 ? `${data.counts.reachable_now} faisables` : null }
                : tab.id === "items" && data
                  ? { main: String(data.items_received.length), highlight: null }
                  : tab.id === "spheres" && data?.spheres && data.spheres.length > 0
                    ? { main: `${data.spheres.filter((s) => s.status === "past").length} / ${data.spheres.length}`, highlight: null }
                  : tab.id === "indices" && hints != null && hints.hints.length > 0
                    ? { main: String(hints.hints.length), highlight: null }
                    : null;
            return (
              <button
                className={`inline-flex flex-col items-start justify-center px-4 py-2.5 text-sm font-medium transition-colors ${
                  activeTab === tab.id
                    ? "border-b-2 border-accent-text text-foreground"
                    : "text-muted-foreground hover:text-foreground"
                }`}
                key={tab.id}
                onClick={() => { switchTab(tab.id); }}
                type="button"
              >
                <span className="flex items-center gap-1.5">
                  <tab.icon aria-hidden="true" className="size-3.5 shrink-0" />
                  {tab.label}
                </span>
                {sub ? (
                  <span className="flex items-center gap-1.5 text-xs font-normal">
                    <span className={activeTab === tab.id ? "text-muted-foreground" : "text-muted-foreground/60"}>
                      {sub.main}
                    </span>
                    {sub.highlight ? (
                      <span className="text-success">{sub.highlight}</span>
                    ) : null}
                  </span>
                ) : null}
              </button>
            );
          })}
        </div>

        {state.kind === "data" ? (
          <div className="grid gap-6">
            {/* Checks tab */}
            {activeTab === "checks" ? (
              <div className="grid gap-6">
                <div className="flex flex-wrap items-center gap-2">
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
                  {goalReached && (
                    <button
                      className="inline-flex items-center gap-1.5 rounded border border-amber-500/40 bg-amber-500/10 px-3 py-1.5 text-xs font-semibold text-amber-400 hover:bg-amber-500/20"
                      onClick={() => {
                        const checksPercent = Math.round((state.data.counts.checked / Math.max(1, state.data.counts.total)) * 100);
                        const itemsTotal = state.data.items_received.length + state.data.items_not_received.length;
                        const itemsPercent = itemsTotal > 0 ? Math.round((state.data.items_received.length / itemsTotal) * 100) : 0;
                        setGoalInfo({
                          slotName: state.data.player,
                          playerAlias: user?.displayName ?? undefined,
                          gameName: state.data.game,
                          checksPercent,
                          itemsPercent,
                        });
                      }}
                      type="button"
                    >
                      🏆 Célébration
                    </button>
                  )}
                </div>
                <div className="grid gap-6 lg:grid-cols-2">
                <CheckListPanel
                  checks={state.data.reachable_unchecked}
                  currentSlot={Number(slotIndex)}
                  emptyMessage="Aucun check faisable avec les items actuels."
                  hintCost={hints?.hintCost ?? 0}
                  hintFree={hintFree}
                  onHintRequest={handleHintLocation}
                  title="Checks faisables maintenant"
                  variant="reachable"
                />
                <CheckListPanel
                  checks={state.data.unreachable_unchecked}
                  currentSlot={Number(slotIndex)}
                  emptyMessage="Tous les checks sont faisables !"
                  hintCost={hints?.hintCost ?? 0}
                  hintFree={hintFree}
                  onHintRequest={handleHintLocation}
                  title="Checks non faisables"
                  variant="unreachable"
                />
                </div>
              </div>
            ) : null}

            {/* Sphères tab */}
            {activeTab === "spheres" ? (
              state.data.spheres && state.data.spheres.length > 0 ? (
                <SphereLine currentSlot={Number(slotIndex)} spheres={state.data.spheres} />
              ) : (
                <p className="text-sm text-muted-foreground">Aucune donnée de sphères disponible.</p>
              )
            ) : null}

            {/* Indices tab */}
            {activeTab === "indices" ? (
              hints ? (
                <HintsPanel
                  data={hints}
                  hintFree={hintFree}
                  onToggleFree={() => { setHintFree((f) => !f); }}
                />
              ) : (
                <p className="text-sm text-muted-foreground">Chargement des indices…</p>
              )
            ) : null}

            {/* Actions tab */}
            {activeTab === "action" ? (
              <div className="grid gap-6">
                {actionError ? (
                  <p className="rounded border border-danger/40 bg-danger/10 px-4 py-2.5 text-sm text-danger">{actionError}</p>
                ) : null}

                {/* Actions immédiates */}
                <section className="grid gap-3">
                  <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Actions immédiates</h3>
                  <div className="grid gap-3 sm:grid-cols-2">
                    {([
                      { id: "collect",        label: "Collect",           icon: Download,       description: "Collecte tous les items en attente pour ce slot." },
                      { id: "release",        label: "Release",           icon: SendHorizontal, description: "Envoie tous les items restants de ce slot vers leurs destinations." },
                      { id: "allow_release",  label: "Autoriser Release", icon: ShieldCheck,    description: "Autorise le joueur à utiliser la commande !release." },
                      { id: "forbid_release", label: "Interdire Release", icon: ShieldOff,      description: "Retire l'autorisation d'utiliser la commande !release." },
                    ] as const).map((action) => {
                      const isLoading = actionLoading === action.id;
                      return (
                        <div className="flex flex-col gap-3 rounded border border-border bg-surface p-4" key={action.id}>
                          <div className="flex items-start gap-3">
                            <div className="flex size-8 shrink-0 items-center justify-center rounded border border-border bg-surface-2">
                              <action.icon aria-hidden="true" className="size-4 text-muted-foreground" />
                            </div>
                            <div className="grid gap-0.5">
                              <p className="text-sm font-semibold text-foreground">{action.label}</p>
                              <p className="text-xs text-muted-foreground">{action.description}</p>
                            </div>
                          </div>
                          <button
                            className="inline-flex w-full items-center justify-center gap-2 rounded border border-border bg-surface-2 px-4 py-2 text-sm font-medium text-foreground transition-colors hover:border-accent hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-50"
                            disabled={actionLoading !== null}
                            onClick={() => {
                              const cmd = (action.id === "allow_release" || action.id === "forbid_release")
                                ? `!admin /${action.id}`
                                : `!admin /${action.id} ${state.data.player}`;
                              void sendBridgeCommand(cmd, action.id);
                            }}
                            type="button"
                          >
                            {isLoading
                              ? <Loader2 aria-hidden="true" className="size-3.5 animate-spin" />
                              : <action.icon aria-hidden="true" className="size-3.5" />}
                            {action.label}
                          </button>
                        </div>
                      );
                    })}
                  </div>
                </section>

                {/* Envoyer un item */}
                <section className="rounded border border-border bg-surface p-5">
                  <div className="mb-4 flex items-center gap-2">
                    <Gift aria-hidden="true" className="size-4 text-muted-foreground" />
                    <h3 className="text-sm font-semibold text-foreground">Envoyer un item</h3>
                  </div>
                  {(() => {
                    const q = itemSearch.trim().toLowerCase();
                    const suggestions = q.length === 0 ? [] : Array.from(
                      new Map(
                        [...state.data.items_received, ...state.data.items_not_received]
                          .map((i) => [i.id, i])
                      ).values()
                    ).filter((i) => i.name.toLowerCase().includes(q)).slice(0, 8);
                    const isLoading = actionLoading === "send_item";
                    const canSubmit = itemSearch.trim().length > 0 && itemQty >= 1;
                    return (
                      <div className="flex flex-wrap gap-2">
                        <div className="relative min-w-0 flex-1">
                          {itemSuggestOpen && suggestions.length > 0 ? (
                            <ul className="absolute bottom-full left-0 right-0 z-10 mb-1 max-h-52 overflow-y-auto rounded border border-border bg-surface shadow-lg">
                              {suggestions.map((item) => (
                                <li key={item.id}>
                                  <button
                                    className="w-full px-3 py-2 text-left text-sm text-foreground hover:bg-surface-2"
                                    onMouseDown={(e) => {
                                      e.preventDefault();
                                      setItemSearch(item.name);
                                      setItemSuggestOpen(false);
                                    }}
                                    type="button"
                                  >
                                    {item.name}
                                  </button>
                                </li>
                              ))}
                            </ul>
                          ) : null}
                          <input
                            className="h-8 w-full rounded border border-border bg-surface-2 px-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-accent focus:outline-none"
                            onBlur={() => { setItemSuggestOpen(false); }}
                            onChange={(e) => { setItemSearch(e.target.value); setItemSuggestOpen(true); }}
                            onFocus={() => { setItemSuggestOpen(true); }}
                            placeholder="Nom de l'item…"
                            type="text"
                            value={itemSearch}
                          />
                        </div>
                        <input
                          className="h-8 w-20 rounded border border-border bg-surface-2 px-3 text-sm text-foreground focus:border-accent focus:outline-none"
                          min={1}
                          onChange={(e) => { setItemQty(Math.max(1, parseInt(e.target.value, 10) || 1)); }}
                          title="Quantité"
                          type="number"
                          value={itemQty}
                        />
                        <button
                          className="inline-flex items-center gap-2 rounded border border-border bg-surface-2 px-4 py-1.5 text-sm font-medium text-foreground transition-colors hover:border-accent hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-50"
                          disabled={!canSubmit || actionLoading !== null}
                          onClick={() => {
                            const cmd = itemQty > 1
                              ? `!admin /send_multiple ${itemQty} ${state.data.player} ${itemSearch.trim()}`
                              : `!admin /send ${state.data.player} ${itemSearch.trim()}`;
                            void sendBridgeCommand(cmd, "send_item");
                          }}
                          type="button"
                        >
                          {isLoading
                            ? <Loader2 aria-hidden="true" className="size-3.5 animate-spin" />
                            : <SendHorizontal aria-hidden="true" className="size-3.5" />}
                          Envoyer
                        </button>
                      </div>
                    );
                  })()}
                </section>

                {/* Déclencher une localisation */}
                <section className="rounded border border-border bg-surface p-5">
                  <div className="mb-4 flex items-center gap-2">
                    <MapPin aria-hidden="true" className="size-4 text-muted-foreground" />
                    <h3 className="text-sm font-semibold text-foreground">Déclencher une localisation</h3>
                  </div>
                  {(() => {
                    const q = locationSearch.trim().toLowerCase();
                    const suggestions = q.length === 0 ? [] : [
                      ...state.data.reachable_unchecked,
                      ...state.data.unreachable_unchecked,
                    ].filter((c) => c.name.toLowerCase().includes(q)).slice(0, 8);
                    const isLoading = actionLoading === "send_location";
                    const canSubmit = locationSearch.trim().length > 0;
                    return (
                      <div className="flex gap-2">
                        <div className="relative min-w-0 flex-1">
                          {locationSuggestOpen && suggestions.length > 0 ? (
                            <ul className="absolute bottom-full left-0 right-0 z-10 mb-1 max-h-52 overflow-y-auto rounded border border-border bg-surface shadow-lg">
                              {suggestions.map((check) => (
                                <li key={check.id}>
                                  <button
                                    className="w-full px-3 py-2 text-left text-sm text-foreground hover:bg-surface-2"
                                    onMouseDown={(e) => {
                                      e.preventDefault();
                                      setLocationSearch(check.name);
                                      setLocationSuggestOpen(false);
                                    }}
                                    type="button"
                                  >
                                    {check.name}
                                  </button>
                                </li>
                              ))}
                            </ul>
                          ) : null}
                          <input
                            className="h-8 w-full rounded border border-border bg-surface-2 px-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-accent focus:outline-none"
                            onBlur={() => { setLocationSuggestOpen(false); }}
                            onChange={(e) => { setLocationSearch(e.target.value); setLocationSuggestOpen(true); }}
                            onFocus={() => { setLocationSuggestOpen(true); }}
                            placeholder="Nom de la localisation…"
                            type="text"
                            value={locationSearch}
                          />
                        </div>
                        <button
                          className="inline-flex items-center gap-2 rounded border border-border bg-surface-2 px-4 py-1.5 text-sm font-medium text-foreground transition-colors hover:border-accent hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-50"
                          disabled={!canSubmit || actionLoading !== null}
                          onClick={() => { void sendBridgeCommand(`!admin /send_location ${state.data.player} ${locationSearch.trim()}`, "send_location"); }}
                          type="button"
                        >
                          {isLoading
                            ? <Loader2 aria-hidden="true" className="size-3.5 animate-spin" />
                            : <MapPin aria-hidden="true" className="size-3.5" />}
                          Déclencher
                        </button>
                      </div>
                    );
                  })()}
                </section>

                {/* Alias du joueur */}
                <section className="rounded border border-border bg-surface p-5">
                  <div className="mb-4 flex flex-wrap items-center gap-2">
                    <Tag aria-hidden="true" className="size-4 text-muted-foreground" />
                    <h3 className="text-sm font-semibold text-foreground">Alias du joueur</h3>
                    <span className="ml-1 font-mono text-xs text-muted-foreground">
                      Actuellement : {state.data.player}
                    </span>
                  </div>
                  <div className="flex gap-2">
                    <input
                      className="h-8 min-w-0 flex-1 rounded border border-border bg-surface-2 px-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-accent focus:outline-none"
                      onChange={(e) => { setAliasInput(e.target.value); }}
                      placeholder="Nouvel alias…"
                      type="text"
                      value={aliasInput}
                    />
                    <button
                      className="inline-flex items-center gap-2 rounded border border-border bg-surface-2 px-4 py-1.5 text-sm font-medium text-foreground transition-colors hover:border-accent hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-50"
                      disabled={aliasInput.trim().length === 0 || actionLoading !== null}
                      onClick={() => { void sendBridgeCommand(`!admin /alias ${state.data.player} ${aliasInput.trim()}`, "alias"); }}
                      type="button"
                    >
                      {actionLoading === "alias"
                        ? <Loader2 aria-hidden="true" className="size-3.5 animate-spin" />
                        : <Tag aria-hidden="true" className="size-3.5" />}
                      Définir
                    </button>
                  </div>
                </section>
              </div>
            ) : null}

            {/* Items tab */}
            {activeTab === "items" ? (
              <div className="grid gap-6 lg:grid-cols-2">
                <ItemListPanel
                  emptyMessage="Aucun item reçu."
                  itemLocations={itemLocations}
                  items={state.data.items_received}
                  title="Items reçus"
                  variant="received"
                />
                <ItemListPanel
                  emptyMessage="Tous les items ont été reçus !"
                  hintCost={hints?.hintCost ?? 0}
                  hintFree={hintFree}
                  itemLocations={itemLocations}
                  items={state.data.items_not_received ?? []}
                  onHintRequest={handleHintItem}
                  title="Items non reçus"
                  variant="not-received"
                />
              </div>
            ) : null}
          </div>
        ) : null}
      </section>
    </>
  );
}
