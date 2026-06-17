"use client";

import { Search } from "lucide-react";
import { useEffect, useState } from "react";

import { env } from "@/lib/env";
import { fetchOverlaySubscribe } from "./overlay-api";
import type { OverlayParams } from "./overlay-params";
import { posToContainerClasses } from "./overlay-params";

// Fixed, readable text size (px); `?scale` multiplies it. The row is sized in `em` off this.
const BASE_FONT_PX = 15;

// The reachable list a player can do now - same data as the progression page's "Checks faisables
// maintenant" (`reachable_unchecked`). Each entry just needs its location name here.
function parseReachableNames(payload: unknown): string[] | null {
  if (typeof payload !== "object" || payload === null) return null;
  const data: unknown = "data" in payload ? (payload as { data: unknown }).data : payload;
  if (typeof data !== "object" || data === null || !("reachable_unchecked" in data)) return null;
  const list: unknown = (data as { reachable_unchecked: unknown }).reachable_unchecked;
  if (!Array.isArray(list)) return null;
  return list
    .map((c) => (typeof c === "object" && c !== null && "name" in c && typeof c.name === "string" ? c.name : null))
    .filter((n): n is string => n !== null);
}

const DEMO_CHECKS = ["Bowser - Étoile 1", "Temple de l'Eau - Boss", "Forêt Kokiri - Coffre", "Plaine - PNJ"];

export function ReachableOverlay({
  sessionId,
  params,
}: {
  sessionId: string;
  params: OverlayParams;
}) {
  const [checks, setChecks] = useState<string[]>([]);
  const slot = params.slots[0] ?? null; // reachability is per-slot, like the progression page

  // Snapshot (anonymous GET, same endpoint the progression page uses) + live updates over the slot's
  // reachable SSE topic. Mirrors the progression page (initial fetch + Mercure stream).
  useEffect(() => {
    if (params.demo || !sessionId || slot === null) return;
    let cancelled = false;
    let es: EventSource | null = null;
    let reconnect: ReturnType<typeof setTimeout> | null = null;

    async function snapshot(): Promise<void> {
      try {
        const res = await fetch(
          `${env.apiBaseUrl}/sessions/${encodeURIComponent(sessionId)}/slots/${encodeURIComponent(String(slot))}/reachable`,
        );
        if (!res.ok || cancelled) return;
        const names = parseReachableNames(await res.json());
        if (!cancelled && names) setChecks(names);
      } catch {
        /* non-critical - the SSE will fill in on the next recompute */
      }
    }

    async function connect(): Promise<void> {
      const sub = await fetchOverlaySubscribe(sessionId);
      if (cancelled || !sub?.hubUrl) return;
      const url = new URL(sub.hubUrl);
      url.searchParams.set("topic", `runs/${sessionId}/slots/${slot}/reachable`);
      url.searchParams.set("authorization", sub.token);
      const source = new EventSource(url.toString());
      es = source;
      source.onmessage = (event) => {
        try {
          const names = parseReachableNames(JSON.parse(event.data as string));
          if (names) setChecks(names);
        } catch {
          /* ignore malformed frames */
        }
      };
      source.onerror = () => {
        source.close();
        es = null;
        if (!cancelled) reconnect = setTimeout(() => void connect(), 5_000);
      };
    }

    void snapshot();
    void connect();

    return () => {
      cancelled = true;
      es?.close();
      if (reconnect) clearTimeout(reconnect);
    };
  }, [sessionId, slot, params.demo]);

  useEffect(() => {
    if (!params.demo) return;
    const raf = requestAnimationFrame(() => setChecks(DEMO_CHECKS));
    return () => cancelAnimationFrame(raf);
  }, [params.demo]);

  const fontSize = BASE_FONT_PX * params.scale;
  // Reachability is per-slot; without a `?slot=` there's nothing to show (don't display stale data).
  const shown = !params.demo && slot === null ? [] : checks;

  return (
    <div className={`pointer-events-none fixed inset-0 flex overflow-hidden p-[1.5vmin] ${posToContainerClasses(params.pos)}`}>
      <ul className="flex w-full flex-col gap-[0.4em]" style={{ fontSize }}>
        {shown.map((name, i) => (
          <li
            className="flex items-center gap-[0.5em] rounded border-l-4 border-l-teal-500 bg-black/70 px-[0.7em] py-[0.35em] backdrop-blur-sm"
            key={`${name}-${i}`}
          >
            <Search aria-hidden className="size-[1em] shrink-0 text-teal-300" />
            <span className="min-w-0 flex-1 truncate text-[1em] font-medium text-white drop-shadow">{name}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}
