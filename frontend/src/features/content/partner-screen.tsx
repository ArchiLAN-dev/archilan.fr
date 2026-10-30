"use client";

import Image from "next/image";
import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";

import { parseOverlayParams, posToContainerClasses, posToTransformOrigin, type OverlayParams } from "@/features/overlay/overlay-params";
import { cn } from "@/lib/utils";
import { fetchPartnerScreen } from "./partner-screen-api";
import type { EventPartner } from "./partners-api";

export type PartnerScreenMode = "overlay" | "ecran";

export type PartnerScreenParams = {
  mode: PartnerScreenMode;
  eventId: string | null;
  overlay: OverlayParams;
  /** Seconds each partner stays on: `duration`, else 8 s on the overlay and a slow 12 s on the screen. */
  seconds: number;
};

/** A partner added or hidden in the admin reaches OBS on the next read, without touching the source. */
export const PARTNER_SCREEN_REFRESH_MS = 5 * 60 * 1000;

const EVENT_ID_PATTERN = /^[A-Za-z0-9_-]{1,64}$/;

/** Story 45.4: the screen's own reading of the overlay parameters (`pos` defaults to the bottom right corner). */
export function parsePartnerScreenParams(sp: URLSearchParams): PartnerScreenParams {
  const mode: PartnerScreenMode = sp.get("mode") === "ecran" ? "ecran" : "overlay";
  const rawEvent = sp.get("event");
  const overlay = parseOverlayParams(sp);
  return {
    mode,
    eventId: rawEvent !== null && EVENT_ID_PATTERN.test(rawEvent) ? rawEvent : null,
    overlay: sp.get("pos") === null ? { ...overlay, pos: "bottom-right" } : overlay,
    seconds: overlay.duration > 0 ? overlay.duration : mode === "ecran" ? 12 : 8,
  };
}

/** The link to paste in OBS (overlay) or to open on the LAN screen. */
export function partnerScreenUrl(appUrl: string, options: { eventId: string | null; mode: PartnerScreenMode }): string {
  const params = new URLSearchParams();
  if (options.eventId !== null) params.set("event", options.eventId);
  if (options.mode === "ecran") params.set("mode", "ecran");
  const qs = params.toString();
  return `${appUrl}/o/partenaires${qs ? `?${qs}` : ""}`;
}

/** The partner on air at `tick`: one after the other, back to the first after the last. */
export function rotationIndex(tick: number, count: number): number {
  return count === 0 ? 0 : tick % count;
}

function useRotation(count: number, seconds: number): number {
  const [tick, setTick] = useState(0);
  useEffect(() => {
    if (count < 2) return;
    const id = window.setInterval(() => setTick((current) => current + 1), seconds * 1000);
    return () => window.clearInterval(id);
  }, [count, seconds]);
  return rotationIndex(tick, count);
}

/** Story 45.4: the partners on stream (OBS browser source) or on the LAN screen, re-read every few minutes. */
export function PartnerScreen({ params }: { params: PartnerScreenParams }) {
  const { data } = useQuery({
    queryKey: ["partner-screen", params.eventId],
    queryFn: async () => (await fetchPartnerScreen(params.eventId)) ?? Promise.reject(new Error("partner screen read failed")),
    staleTime: PARTNER_SCREEN_REFRESH_MS,
    refetchInterval: PARTNER_SCREEN_REFRESH_MS,
    // A failed read keeps the list on air; the next interval tries again.
    retry: false,
  });
  const partners = data ?? [];
  const index = useRotation(partners.length, params.seconds);
  const current = partners.at(index) ?? null;

  // The overlay must composite over the game capture: a transparent document, or the chroma key asked for.
  const overlayBackground = params.mode === "overlay" ? (params.overlay.bg !== null ? `#${params.overlay.bg}` : "transparent") : null;
  useEffect(() => {
    if (overlayBackground === null) return;
    const prevBody = document.body.style.background;
    const prevHtml = document.documentElement.style.background;
    document.body.style.background = overlayBackground;
    document.documentElement.style.background = overlayBackground;
    return () => {
      document.body.style.background = prevBody;
      document.documentElement.style.background = prevHtml;
    };
  }, [overlayBackground]);

  if (params.mode === "ecran") {
    return <PartnerScreenWall current={current} ids={partners.map((item) => item.partner.id)} />;
  }
  return <PartnerOverlay current={current} params={params.overlay} />;
}

type OverlayProps = { current: EventPartner | null; params: OverlayParams };

/** One partner at a time, anchored where the streamer wants it. Nothing at all with no partner to show. */
export function PartnerOverlay({ current, params }: OverlayProps) {
  if (current === null) return null;
  const { partner, mention } = current;
  return (
    <div className={cn("fixed inset-0 flex p-6", posToContainerClasses(params.pos))}>
      <div
        className="grid w-72 justify-items-center gap-2"
        key={partner.id}
        // Dynamic values Tailwind cannot express: the streamer's scale, grown away from the anchored edge.
        style={{ transform: `scale(${params.scale})`, transformOrigin: posToTransformOrigin(params.pos) }}
      >
        {/* Fixed white chip, like the site's cards: a partner's logo is drawn for a light background (45.2). */}
        <span className="flex h-28 w-full items-center justify-center rounded-2xl bg-white p-4 shadow-lg">
          {partner.logoUrl !== null ? (
            <Image alt={`Logo de ${partner.name}`} className="max-h-full max-w-full w-auto object-contain" height={80} src={partner.logoUrl} width={240} />
          ) : null}
        </span>
        <span className="text-center text-lg font-semibold text-white drop-shadow">{partner.name}</span>
        {mention !== null ? <span className="text-center text-sm text-white/80 drop-shadow">{mention}</span> : null}
      </div>
    </div>
  );
}

type WallProps = { current: EventPartner | null; ids: string[] };

/** The LAN screen: readable from the back of the room, one partner at a time, slowly. */
export function PartnerScreenWall({ current, ids }: WallProps) {
  return (
    <main className="flex min-h-dvh flex-col items-center justify-center gap-12 bg-background p-10 text-center">
      <h1 className="font-heading text-5xl font-bold text-foreground md:text-7xl">Merci à nos partenaires</h1>
      {current !== null ? (
        <div className="grid w-full max-w-4xl justify-items-center gap-6" key={current.partner.id}>
          <span className="flex h-64 w-full max-w-2xl items-center justify-center rounded-3xl bg-white p-10">
            {current.partner.logoUrl !== null ? (
              <Image
                alt={`Logo de ${current.partner.name}`}
                className="max-h-full max-w-full w-auto object-contain"
                height={200}
                src={current.partner.logoUrl}
                width={600}
              />
            ) : null}
          </span>
          <p className="font-heading text-4xl font-semibold text-foreground md:text-5xl">{current.partner.name}</p>
          {(current.mention ?? current.partner.description) !== "" ? (
            <p className="text-2xl text-muted-foreground md:text-3xl">{current.mention ?? current.partner.description}</p>
          ) : null}
        </div>
      ) : null}
      {ids.length > 1 ? (
        <ol aria-label="Partenaires" className="flex gap-3">
          {ids.map((id) => (
            <li
              aria-current={id === current?.partner.id ? "true" : undefined}
              className={cn("size-3 rounded-full", id === current?.partner.id ? "bg-accent" : "bg-border")}
              key={id}
            />
          ))}
        </ol>
      ) : null}
    </main>
  );
}
