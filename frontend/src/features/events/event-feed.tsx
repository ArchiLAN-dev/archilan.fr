"use client";

import { useEffect, useRef, useState } from "react";
import { Gift, Info, Lightbulb, MapPin, MessageSquare, WifiOff } from "lucide-react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

// ─── Types ───────────────────────────────────────────────────────────────────

type FeedActor = { slot: number; name: string; game: string };
type FeedRef = { id: number; name: string };

type FeedEvent = {
  type: string;
  text: string;
  color?: string;
  timestamp: string;
  // Item events only (story 29.4): structured origin, present when resolved. Fall back to `text`.
  item?: FeedRef;
  location?: FeedRef;
  sender?: FeedActor;
  receiver?: FeedActor;
};

type FeedMessage = FeedEvent & { _key: string };

type FeedState =
  | { kind: "loading" }
  | { kind: "unavailable" }
  | { kind: "active"; messages: FeedMessage[]; connected: boolean };

// ─── Badge config ─────────────────────────────────────────────────────────────

const TYPE_LABELS: Record<string, string> = {
  hint: "Indice",
  "item-received": "Objet",
  "location-checked": "Location",
  system: "Système",
  chat: "Chat",
};

const TYPE_CLASSES: Record<string, string> = {
  hint: "bg-amber-500/20 text-amber-400",
  "item-received": "bg-teal-500/20 text-teal-400",
  "location-checked": "bg-blue-500/20 text-blue-400",
  system: "bg-muted text-muted-foreground",
  chat: "bg-foreground/10 text-foreground",
};

const TYPE_BORDER: Record<string, string> = {
  hint: "border-l-amber-500 bg-amber-500/5",
  "item-received": "border-l-teal-500 bg-teal-500/5",
  "location-checked": "border-l-blue-500 bg-blue-500/5",
  system: "border-l-border bg-transparent",
  chat: "border-l-foreground/30 bg-transparent",
};

type IconComponent = React.ComponentType<{ className?: string; "aria-hidden"?: boolean }>;

const TYPE_ICONS: Record<string, IconComponent> = {
  hint: Lightbulb,
  "item-received": Gift,
  "location-checked": MapPin,
  system: Info,
  chat: MessageSquare,
};

// ─── Component ───────────────────────────────────────────────────────────────

export function EventFeed({ runId }: { runId: string }) {
  const [state, setState] = useState<FeedState>({ kind: "loading" });
  const [newCount, setNewCount] = useState(0);
  const esRef = useRef<EventSource | null>(null);
  const reconnectTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const containerRef = useRef<HTMLDivElement>(null);
  // true = user scrolled down (looking at old messages); newest are at the top
  const isScrolledDown = useRef(false);

  function scrollToTop() {
    if (containerRef.current) containerRef.current.scrollTop = 0;
    setNewCount(0);
  }

  useEffect(() => {
    const el = containerRef.current;
    if (!el) return;
    function onScroll() {
      if (!el) return;
      isScrolledDown.current = el.scrollTop > 50;
      if (!isScrolledDown.current) setNewCount(0);
    }
    el.addEventListener("scroll", onScroll, { passive: true });
    return () => { el.removeEventListener("scroll", onScroll); };
  }, [state.kind]);

  useEffect(() => {
    let cancelled = false;

    function connect(token: string, hubUrl: string, topic: string): void {
      if (cancelled) return;

      const url = new URL(hubUrl);
      url.searchParams.set("topic", topic);
      url.searchParams.set("authorization", token);
      const es = new EventSource(url.toString());
      esRef.current = es;

      es.onopen = () => {
        setState((prev) => {
          if (prev.kind === "active") return { ...prev, connected: true };
          return { kind: "active", messages: [], connected: true };
        });
      };

      es.onmessage = (event) => {
        try {
          const data = JSON.parse(event.data as string) as FeedEvent;
          const msg: FeedMessage = {
            ...data,
            _key: `${Date.now()}-${Math.random()}`,
          };
          setState((prev) => {
            if (prev.kind === "loading") {
              return { kind: "active", messages: [msg], connected: true };
            }
            if (prev.kind !== "active") return prev;
            return {
              ...prev,
              messages: [msg, ...prev.messages].slice(0, 100),
              connected: true,
            };
          });
          if (isScrolledDown.current) {
            setNewCount((n) => n + 1);
          }
        } catch {
          /* ignore malformed */
        }
      };

      es.onerror = () => {
        es.close();
        esRef.current = null;
        setState((prev) => {
          if (prev.kind === "active") return { ...prev, connected: false };
          return prev;
        });
        if (!cancelled) {
          reconnectTimerRef.current = setTimeout(
            () => { connect(token, hubUrl, topic); },
            5_000,
          );
        }
      };
    }

    async function init(): Promise<void> {
      const res = await apiFetch(
        `${env.apiBaseUrl}/sessions/${runId}/feed-token`,
      );

      if (cancelled) return;

      if (!res.ok) {
        setState({ kind: "unavailable" });
        return;
      }

      const json = (await res.json()) as {
        data: { token: string; hubUrl: string; topic: string };
      };
      const { token, hubUrl, topic } = json.data;

      if (cancelled || !hubUrl) {
        setState({ kind: "unavailable" });
        return;
      }

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

  if (state.kind === "loading") {
    return (
      <section className="grid gap-3">
        <div className="h-6 w-36 animate-pulse rounded bg-surface-2" aria-hidden="true" />
        <span className="sr-only">Connexion au feed en direct…</span>
        <div aria-hidden="true" className="rounded border border-border bg-surface divide-y divide-border">
          {[0, 1, 2, 3, 4].map((i) => (
            <div key={i} className="flex items-center gap-3 px-4 py-2.5 border-l-4 border-l-surface-2">
              <div className="h-4 w-14 animate-pulse rounded-full bg-surface-2 shrink-0" />
              <div className="h-3 w-3/4 animate-pulse rounded bg-surface-2" />
            </div>
          ))}
        </div>
      </section>
    );
  }

  if (state.kind === "unavailable") {
    return null;
  }

  const { messages, connected } = state;

  return (
    <section className="grid gap-3">
      <div className="flex items-center justify-between gap-3">
        <h2 className="font-heading text-xl font-semibold text-foreground">
          Feed en direct
        </h2>
        {!connected && (
          <span className="inline-flex items-center gap-1.5 text-xs text-accent-warm">
            <WifiOff aria-hidden="true" className="size-3.5" />
            Reconnexion…
          </span>
        )}
      </div>

      <div className="relative">
        {newCount > 0 ? (
          <button
            className="absolute top-3 left-1/2 z-10 -translate-x-1/2 cursor-pointer rounded-full bg-accent px-3 py-1 text-xs font-semibold text-accent-text shadow"
            onClick={scrollToTop}
            type="button"
          >
            {newCount} nouveau{newCount > 1 ? "x" : ""} message{newCount > 1 ? "s" : ""} ↑
          </button>
        ) : null}

        <div
          className="max-h-96 overflow-y-auto rounded border border-border bg-surface"
          ref={containerRef}
        >
          {messages.length === 0 ? (
            <p className="p-4 text-sm text-muted-foreground">
              Les messages apparaîtront en direct
            </p>
          ) : (
            <ul className="divide-y divide-border">
              {messages.map((msg) => {
                const borderCls = TYPE_BORDER[msg.type] ?? "border-l-border bg-transparent";
                return (
                  <li
                    className={`flex flex-wrap items-start gap-2 border-l-4 px-4 py-2.5 ${borderCls}`}
                    key={msg._key}
                  >
                    <time
                      className="mt-0.5 shrink-0 text-xs text-muted-foreground"
                      title={msg.timestamp}
                    >
                      {relativeTime(msg.timestamp)}
                    </time>
                    <TypeBadge type={msg.type} />
                    <span className="flex-1 text-sm text-foreground">
                      {msg.item?.name ?? msg.text}
                      {itemOrigin(msg) ? (
                        <span className="text-muted-foreground"> - {itemOrigin(msg)}</span>
                      ) : null}
                    </span>
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      </div>
    </section>
  );
}

// ─── TypeBadge ────────────────────────────────────────────────────────────────

function TypeBadge({ type }: { type: string }) {
  const label = TYPE_LABELS[type] ?? type;
  const cls = TYPE_CLASSES[type] ?? "bg-muted text-muted-foreground";
  const Icon = TYPE_ICONS[type];

  return (
    <span
      className={`inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ${cls}`}
    >
      {Icon ? <Icon aria-hidden className="size-3" /> : null}
      {label}
    </span>
  );
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

// Origin of an item event: "{check} - {world} ({sender})", or "" when the structured origin is absent
// (older bridge / non-item event) so the row degrades to plain `text`.
function itemOrigin(msg: FeedEvent): string {
  if (!msg.sender) return "";
  const head = [msg.location?.name, msg.sender.game].filter((s) => !!s).join(" - ");
  return head ? `${head} (${msg.sender.name})` : msg.sender.name;
}

// Recalculated only on re-render triggered by new messages - no setInterval needed.
function relativeTime(iso: string): string {
  try {
    const diff = Date.now() - new Date(iso).getTime();
    if (diff < 60_000) return "à l'instant";
    if (diff < 3_600_000) return `il y a ${Math.floor(diff / 60_000)} min`;
    return `il y a ${Math.floor(diff / 3_600_000)} h`;
  } catch {
    return iso;
  }
}
