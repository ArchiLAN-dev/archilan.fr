"use client";

import {
  AlertTriangle,
  ArrowLeft,
  Clock,
  Lightbulb,
  ListChecks,
  Loader2,
  Package,
  RefreshCw,
  Route,
  WifiOff,
} from "lucide-react";
import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { type ElementType, use, useCallback, useEffect, useLayoutEffect, useRef, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { useAuth } from "@/features/auth/auth-context";
import { CheckListPanel, ItemListPanel } from "@/features/reachability/check-panels";
import { FanfarePicker } from "@/features/reachability/fanfare-picker";
import { GoalCelebration } from "@/features/reachability/goal-celebration";
import { HintsPanel } from "@/features/reachability/hints-panel";
import { ItemToast } from "@/features/reachability/item-toast";
import type { HintsData, ReachabilityData, ToastItem } from "@/features/reachability/types";
import { fetchCurrentWeeklyRuns, relaunchWeeklyEntry } from "./weekly-runs-api";

type SlotInfo = { index: string; name: string };

type PageState =
  | { kind: "idle" }
  | { kind: "loading" }
  | { kind: "data"; data: ReachabilityData }
  | { kind: "error"; message: string };

// ─── Connection panel ─────────────────────────────────────────────────────────

function CopyButton({ value }: { value: string }) {
  const [copied, setCopied] = useState(false);
  function handleCopy() {
    navigator.clipboard.writeText(value).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }).catch(() => undefined);
  }
  return (
    <button
      className="ml-2 rounded border border-border px-2 py-0.5 text-xs text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
      onClick={handleCopy}
      type="button"
    >
      {copied ? "Copié !" : "Copier"}
    </button>
  );
}

// ─── Main page ────────────────────────────────────────────────────────────────

export function WeeklyRunSlotPage({
  params,
}: {
  params: Promise<{ weeklyRunId: string }>;
}) {
  const { weeklyRunId } = use(params);
  const { user, loading: authLoading } = useAuth();
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const queryClient = useQueryClient();

  // Run data via TanStack Query (shared cache with the main /runs-hebdo page)
  const { data: runs = [], isLoading: runsLoading } = useQuery({
    queryKey: ["weekly-runs", "current"],
    queryFn: fetchCurrentWeeklyRuns,
    staleTime: DEFAULT_STALE_TIME,
    // While a relaunch is in flight, poll fast so the page leaves the "serveur en pause" /
    // restarting screen and shows the live tracking on its own; else a slow refresh detects a
    // killed container within a minute. (Story 17.13)
    refetchInterval: (query) =>
      (query.state.data ?? []).some((r) => r.myEntry?.sessionStatus === "restarting") ? 3_000 : 60_000,
  });
  const run = runs.find((r) => r.weeklyRunId === weeklyRunId) ?? null;
  const myEntry = run?.myEntry ?? null;
  const entryId = myEntry?.entryId ?? null;

  // Redirect unauthenticated users - but only once the session has finished
  // resolving. On a cold load `user` is null while `authLoading` is true; redirecting
  // then would bounce an authenticated user to /connexion before the profile resolves.
  useEffect(() => {
    if (!authLoading && !user) {
      router.push(`/connexion?returnTo=${encodeURIComponent(`/runs-hebdo/${weeklyRunId}/ma-run`)}`);
    }
  }, [authLoading, user, weeklyRunId, router]);

  const entryBaseUrl = entryId
    ? `${env.apiBaseUrl}/weekly-runs/${weeklyRunId}/entries/${entryId}`
    : null;

  // ─── Tabs ──────────────────────────────────────────────────────────────────

  type TabId = "checks" | "items" | "indices";
  const TABS: { id: TabId; label: string; icon: ElementType }[] = [
    { id: "checks",  label: "Checks",  icon: ListChecks },
    { id: "items",   label: "Items",   icon: Package },
    { id: "indices", label: "Indices", icon: Lightbulb },
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

  // ─── State ─────────────────────────────────────────────────────────────────

  const [slots, setSlots] = useState<SlotInfo[]>([]);
  const [selectedSlot, setSelectedSlot] = useState<string | null>(null);
  const slotIndex = selectedSlot ?? null;

  // Fetch available player slots (exclude Bridge/spectator slots)
  useEffect(() => {
    if (!entryBaseUrl) return;
    apiFetch(`${entryBaseUrl}/players`)
      .then((r) => r.json())
      .then((json: { data?: { slots?: Record<string, { slot_name: string; type?: string }> } }) => {
        const playerSlots = Object.entries(json.data?.slots ?? {})
          .filter(([, s]) => s.type !== "spectator" && s.type !== "group" && s.slot_name !== "Bridge")
          .map(([index, s]) => ({ index, name: s.slot_name }))
          .sort((a, b) => Number(a.index) - Number(b.index));
        setSlots(playerSlots);
        if (playerSlots.length > 0 && selectedSlot === null) {
          setSelectedSlot(playerSlots[0].index);
        }
      })
      .catch(() => undefined);
  }, [entryBaseUrl]); // eslint-disable-line react-hooks/exhaustive-deps

  const [state, setState] = useState<PageState>({ kind: "idle" });
  const [refreshing, setRefreshing] = useState(false);
  const [liveConnected, setLiveConnected] = useState(false);
  const [showDisconnected, setShowDisconnected] = useState(false);
  const [toastQueue, setToastQueue] = useState<ToastItem[]>([]);
  const [hints, setHints] = useState<HintsData | null>(null);
  const [goalInfo, setGoalInfo] = useState<{ slotName: string; playerAlias?: string; gameName: string; checksPercent: number; itemsPercent: number } | null>(null);
  const [goalReachedBySSE, setGoalReachedBySSE] = useState(false);
  const [relaunching, setRelaunching] = useState(false);
  const goalReached = !!myEntry?.goalReachedAt || goalReachedBySSE;

  const goalShownRef = useRef(false);
  const stateRef = useRef(state);
  // eslint-disable-next-line react-hooks/refs
  stateRef.current = state;
  const refreshingRef = useRef(false);
  const esRef = useRef<EventSource | null>(null);
  const hintsEsRef = useRef<EventSource | null>(null);
  const prevItemsRef = useRef<Map<number, number>>(new Map());
  const fetchReachabilityRef = useRef<(silent?: boolean) => Promise<void>>(() => Promise.resolve());

  // ─── Reachability fetch ────────────────────────────────────────────────────

  const fetchReachability = useCallback(async (silent = false) => {
    if (!entryBaseUrl || !slotIndex) return;
    if (silent) {
      if (refreshingRef.current) return;
      refreshingRef.current = true;
      setRefreshing(true);
    } else {
      setState({ kind: "loading" });
    }
    try {
      const res = await apiFetch(
        `${entryBaseUrl}/slots/${slotIndex}/reachable`,
      );
      if (!res.ok) {
        const body = (await res.json().catch(() => ({}))) as { error?: { message?: string } };
        const msg = body.error?.message ?? `Erreur ${res.status}`;
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
  }, [entryBaseUrl, slotIndex]);

  useLayoutEffect(() => {
    fetchReachabilityRef.current = fetchReachability;
  });

  useEffect(() => {
    if (entryBaseUrl && slotIndex) void fetchReachabilityRef.current();
  }, [entryBaseUrl, slotIndex]);

  // ─── Fetch hints ───────────────────────────────────────────────────────────

  useEffect(() => {
    if (!entryBaseUrl || !slotIndex) return;
    async function fetchHints(): Promise<void> {
      try {
        const res = await apiFetch(
          `${entryBaseUrl}/slots/${slotIndex}/hints`,
        );
        if (!res.ok) return;
        const json = (await res.json()) as { data: HintsData };
        setHints(json.data);
      } catch { /* non-critical */ }
    }
    void fetchHints();
  }, [entryBaseUrl, slotIndex]);

  // ─── SSE: reachability ────────────────────────────────────────────────────

  useEffect(() => {
    if (!entryBaseUrl || !slotIndex) return;
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
        `${entryBaseUrl}/slots/${slotIndex}/reachable-token`,
      );
      if (!tokenRes.ok || cancelled) return;
      const tokenJson = (await tokenRes.json()) as { data: { token: string; hubUrl: string; topic: string } };
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
  }, [entryBaseUrl, slotIndex]);

  // ─── SSE: hints ──────────────────────────────────────────────────────────

  useEffect(() => {
    if (!entryBaseUrl || !slotIndex) return;
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
        `${entryBaseUrl}/slots/${slotIndex}/hints-token`,
      );
      if (!tokenRes.ok || cancelled) return;
      const tokenJson = (await tokenRes.json()) as { data: { token: string; hubUrl: string; topic: string } };
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
  }, [entryBaseUrl, slotIndex]);

  // ─── SSE: players state (goal detection) ─────────────────────────────────

  useEffect(() => {
    if (!entryBaseUrl) return;
    let cancelled = false;
    let reconnectTimer: ReturnType<typeof setTimeout> | null = null;

    const slotKey = slotIndex ?? "1";

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
            setGoalReachedBySSE(true);
          }
          if (slot?.client_status === 30 && slot.goal_reached_at && !goalShownRef.current) {
            goalShownRef.current = true;
            const cur = stateRef.current;
            const d = cur.kind === "data" ? cur.data : null;
            const checksPercent = d ? Math.round((d.counts.checked / Math.max(1, d.counts.total)) * 100) : 0;
            const itemsTotal = d ? d.items_received.length + d.items_not_received.length : 0;
            const itemsPercent = d && itemsTotal > 0 ? Math.round((d.items_received.length / itemsTotal) * 100) : 0;
            setGoalInfo({
              slotName: d?.player ?? `Slot ${slotKey}`,
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
      const stateRes = await apiFetch(`${entryBaseUrl}/players`).catch(() => null);
      if (stateRes?.ok && !cancelled) {
        const stateJson = (await stateRes.json()) as { data: { slots?: Record<string, { client_status?: number; goal_reached_at?: string | null }> } };
        const slot = stateJson.data.slots?.[slotKey];
        if (slot?.client_status === 30 && slot.goal_reached_at) {
          setGoalReachedBySSE(true);
        }
      }

      const tokenRes = await apiFetch(`${entryBaseUrl}/players-token`);
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
  }, [entryBaseUrl, slotIndex]); // eslint-disable-line react-hooks/exhaustive-deps -- slotKey + initPlayers() captured via closure

  // ─── Disconnected indicator debounce ─────────────────────────────────────

  useEffect(() => {
    const id = setTimeout(() => { setShowDisconnected(!liveConnected); }, liveConnected ? 0 : 3_000);
    return () => { clearTimeout(id); };
  }, [liveConnected]);

  // ─── Paid hint requests (story 9.31) ─────────────────────────────────────
  // This is the player's own run: hints are always paid (free=false, charged to the
  // slot's points). There is no admin/free path on this surface.

  async function handleHintLocation(locationId: number): Promise<void> {
    if (!entryBaseUrl || !slotIndex) return;
    const res = await apiFetch(
      `${entryBaseUrl}/slots/${slotIndex}/hints/request`,
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ location_id: locationId }),
      },
    );
    if (!res.ok) throw new Error(`hint location failed: ${res.status}`);
  }

  async function handleHintItem(itemName: string): Promise<void> {
    if (!entryBaseUrl || !slotIndex) return;
    const res = await apiFetch(
      `${entryBaseUrl}/slots/${slotIndex}/hints/request-item`,
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ itemName }),
      },
    );
    if (!res.ok) throw new Error(`hint item failed: ${res.status}`);
  }

  // ─── Relaunch a stopped container (story 17.13) ──────────────────────────
  async function handleRelaunch() {
    const sessionId = myEntry?.externalSessionId ?? null;
    if (sessionId === null) return;
    setRelaunching(true);
    const ok = await relaunchWeeklyEntry(sessionId);
    setRelaunching(false);
    if (ok) {
      void queryClient.invalidateQueries({ queryKey: ["weekly-runs", "current"] });
    }
  }

  // ─── Render ───────────────────────────────────────────────────────────────

  // Session still resolving on a cold load: show a loader instead of flashing a
  // blank page or a redirect (the effect above redirects only once auth resolved).
  if (authLoading) {
    return (
      <div className="mx-auto max-w-sm py-20 text-center">
        <Loader2 aria-hidden className="mx-auto size-8 animate-spin text-muted-foreground/40" />
        <p className="mt-4 text-sm text-muted-foreground">Chargement…</p>
      </div>
    );
  }

  if (!user) return null;

  if (runsLoading) {
    return (
      <div className="mx-auto max-w-sm py-20 text-center">
        <Loader2 aria-hidden className="mx-auto size-8 animate-spin text-muted-foreground/40" />
        <p className="mt-4 text-sm text-muted-foreground">Chargement…</p>
      </div>
    );
  }

  if (!run) {
    return (
      <div className="mx-auto max-w-sm py-20 text-center">
        <div className="mb-6 flex justify-center">
          <div className="flex size-16 items-center justify-center rounded-full border border-[color:var(--color-danger)]/30 bg-[color:var(--color-danger)]/5">
            <AlertTriangle aria-hidden className="size-7 text-[color:var(--color-danger)]" />
          </div>
        </div>
        <h1 className="font-heading text-xl font-bold text-foreground">Run introuvable</h1>
        <p className="mt-2 text-sm text-muted-foreground">Ce run n&apos;existe pas ou n&apos;est plus actif.</p>
        <div className="mt-8">
          <Link
            className="inline-flex items-center gap-2 rounded bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
            href="/runs-hebdo"
          >
            <ArrowLeft aria-hidden className="size-4" />
            Retour aux runs hebdo
          </Link>
        </div>
      </div>
    );
  }

  if (!myEntry) {
    return (
      <div className="mx-auto max-w-sm py-20 text-center">
        <h1 className="font-heading text-xl font-bold text-foreground">Non inscrit</h1>
        <p className="mt-2 text-sm text-muted-foreground">Tu n&apos;es pas inscrit à ce run.</p>
        <div className="mt-8">
          <Link
            className="inline-flex items-center gap-2 rounded bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
            href="/runs-hebdo"
          >
            <ArrowLeft aria-hidden className="size-4" />
            Retour aux runs hebdo
          </Link>
        </div>
      </div>
    );
  }

  if (!entryId) {
    return (
      <div className="mx-auto max-w-sm py-20 text-center">
        <Route aria-hidden className="mx-auto mb-4 size-10 text-muted-foreground/40" />
        <h1 className="font-heading text-xl font-bold text-foreground">Partie non lancée</h1>
        <p className="mt-2 text-sm text-muted-foreground">Lance ta partie depuis la page des runs hebdo pour accéder à ton suivi.</p>
        <div className="mt-8">
          <Link
            className="inline-flex items-center gap-2 rounded bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
            href="/runs-hebdo"
          >
            <ArrowLeft aria-hidden className="size-4" />
            Lancer ma partie
          </Link>
        </div>
      </div>
    );
  }

  const gameName = run.templateName ?? run.gameName;
  const backHref = "/runs-hebdo";

  // Story 17.13: the entry is launched but its container may have been stopped for inactivity.
  // Don't show the live tracking against a server that no longer exists — offer a relaunch instead.
  // A null status is a pre-17.13 entry with no Session row; treat it as running.
  const sessionStatus = myEntry.sessionStatus;
  const containerRestarting = sessionStatus === "restarting";
  const containerStopped =
    sessionStatus === "idle" || sessionStatus === "stopped" || sessionStatus === "crashed";

  if (containerStopped || containerRestarting) {
    return (
      <div className="mx-auto max-w-md py-20 text-center">
        <h1 className="font-heading text-xl font-bold text-foreground">{gameName}</h1>
        {containerRestarting ? (
          <div className="mt-6 flex items-center justify-center gap-2.5 text-sm text-muted-foreground">
            <Loader2 aria-hidden className="size-4 shrink-0 animate-spin text-accent-text" />
            <span>Relance du serveur en cours…</span>
          </div>
        ) : (
          <>
            <p className="mt-3 text-sm text-muted-foreground">
              Le serveur a été mis en pause après une période d&apos;inactivité. Relance-le pour
              reprendre ta partie là où elle s&apos;était arrêtée.
            </p>
            <button
              className="mt-6 inline-flex items-center gap-2 rounded bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-60"
              disabled={relaunching}
              onClick={() => void handleRelaunch()}
              type="button"
            >
              {relaunching ? "Relance…" : "Relancer ma partie"}
            </button>
          </>
        )}
        <div className="mt-8">
          <Link
            className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
            href={backHref}
          >
            <ArrowLeft aria-hidden className="size-4" />
            Retour aux runs hebdo
          </Link>
        </div>
      </div>
    );
  }

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
            Run hebdo · Semaine {run.weekNumber}
          </p>
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <div className="flex items-center gap-3">
                <h1 className="font-heading text-4xl font-bold leading-tight text-foreground">
                  {state.kind === "data" ? state.data.player : (user.displayName ?? gameName)}
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
              <p className="mt-1 font-mono text-sm text-muted-foreground">
                {state.kind === "data" ? state.data.game : gameName}
              </p>
            </div>
          </div>

          {/* Connection info */}
          {myEntry.connectionInfo ? (
            <div className="flex flex-wrap gap-4 rounded border border-emerald-500/30 bg-emerald-500/5 px-4 py-3 font-mono text-sm">
              <span className="flex items-center gap-1">
                <span className="text-muted-foreground">Host</span>
                <span className="ml-2 text-foreground">{myEntry.connectionInfo.host}</span>
                <CopyButton value={myEntry.connectionInfo.host} />
              </span>
              <span className="flex items-center gap-1">
                <span className="text-muted-foreground">Port</span>
                <span className="ml-2 text-foreground">{myEntry.connectionInfo.port}</span>
                <CopyButton value={String(myEntry.connectionInfo.port)} />
              </span>
              {myEntry.connectionInfo.password ? (
                <span className="flex items-center gap-1">
                  <span className="text-muted-foreground">Password</span>
                  <span className="ml-2 text-foreground">{myEntry.connectionInfo.password}</span>
                  <CopyButton value={myEntry.connectionInfo.password} />
                </span>
              ) : null}
            </div>
          ) : null}

          <div className="flex items-center justify-between gap-3">
            <nav className="text-sm text-muted-foreground">
              <Link className="inline-flex items-center gap-1 hover:text-foreground" href={backHref}>
                <ArrowLeft aria-hidden="true" className="size-3.5" />
                Retour aux runs hebdo
              </Link>
            </nav>
            {slots.length > 1 && (
              <select
                className="h-8 rounded border border-border bg-surface px-2 pr-7 text-xs text-foreground focus:border-accent-text focus:outline-none"
                onChange={(e) => { setSelectedSlot(e.target.value); }}
                value={slotIndex ?? ""}
              >
                {slots.map((s) => (
                  <option key={s.index} value={s.index}>
                    #{s.index} - {s.name}
                  </option>
                ))}
              </select>
            )}
          </div>
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

        {/* Tab bar */}
        <div className="-mx-4 flex items-stretch gap-0 border-b border-border bg-surface px-4">
          {TABS.map((tab) => {
            const data = state.kind === "data" ? state.data : null;
            const sub =
              tab.id === "checks" && data
                ? { main: `${data.counts.checked} / ${data.counts.total}`, highlight: data.counts.reachable_now > 0 ? `${data.counts.reachable_now} faisables` : null }
                : tab.id === "items" && data
                  ? { main: String(data.items_received.length), highlight: null }
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
                  <div className="ml-auto flex items-center gap-2">
                    <FanfarePicker />
                    <button
                      className="inline-flex items-center gap-1.5 rounded border border-border bg-surface px-3 py-1.5 text-xs font-semibold text-foreground hover:border-accent disabled:opacity-50"
                      disabled={refreshing}
                      onClick={() => { void fetchReachability(); }}
                      type="button"
                    >
                      <RefreshCw aria-hidden="true" className={`size-3.5 ${refreshing ? "animate-spin" : ""}`} />
                      Recalculer
                    </button>
                  </div>
                  {goalReached && (
                    <button
                      className="inline-flex items-center gap-1.5 rounded border border-amber-500/40 bg-amber-500/10 px-3 py-1.5 text-xs font-semibold text-amber-400 hover:bg-amber-500/20"
                      onClick={() => {
                        const checksPercent = Math.round((state.data.counts.checked / Math.max(1, state.data.counts.total)) * 100);
                        const itemsTotal = state.data.items_received.length + state.data.items_not_received.length;
                        const itemsPercent = itemsTotal > 0 ? Math.round((state.data.items_received.length / itemsTotal) * 100) : 0;
                        setGoalInfo({
                          slotName: state.data.player,
                          playerAlias: user.displayName ?? undefined,
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
                    currentSlot={Number(slotIndex ?? 1)}
                    emptyMessage="Aucun check faisable avec les items actuels."
                    hideSpoilers={true}
                    hintCost={hints?.hintCost ?? 0}
                    hintFree={false}
                    onHintRequest={handleHintLocation}
                    title="Checks faisables maintenant"
                    variant="reachable"
                  />
                  <CheckListPanel
                    checks={state.data.unreachable_unchecked}
                    currentSlot={Number(slotIndex ?? 1)}
                    emptyMessage="Tous les checks sont faisables !"
                    hideSpoilers={true}
                    hintCost={hints?.hintCost ?? 0}
                    hintFree={false}
                    onHintRequest={handleHintLocation}
                    title="Checks non faisables"
                    variant="unreachable"
                  />
                </div>
              </div>
            ) : null}

            {/* Items tab */}
            {activeTab === "items" ? (
              <div className="grid gap-6 lg:grid-cols-2">
                <ItemListPanel
                  emptyMessage="Aucun item reçu."
                  hideSpoilers={true}
                  itemLocations={{}}
                  items={state.data.items_received}
                  title="Items reçus"
                  variant="received"
                />
                <ItemListPanel
                  emptyMessage="Tous les items ont été reçus !"
                  hideSpoilers={true}
                  hintCost={hints?.hintCost ?? 0}
                  hintFree={false}
                  itemLocations={{}}
                  items={state.data.items_not_received ?? []}
                  onHintRequest={handleHintItem}
                  title="Items non reçus"
                  variant="not-received"
                />
              </div>
            ) : null}

            {/* Indices tab */}
            {activeTab === "indices" ? (
              hints ? (
                <HintsPanel data={hints} />
              ) : (
                <p className="text-sm text-muted-foreground">Chargement des indices…</p>
              )
            ) : null}
          </div>
        ) : null}
      </section>
    </>
  );
}
