"use client";

import { useEffect } from "react";
import { useQuery } from "@tanstack/react-query";
import { Compass, Crown } from "lucide-react";

import { parseOverlayParams, posToContainerClasses, posToTransformOrigin, type OverlayParams } from "@/features/overlay/overlay-params";
import { cn } from "@/lib/utils";
import { fetchMinimapperzBoard, type MinimapperzBoard, type MinimapperzBoardLine } from "./minimapperz-api";

export type MinimapperzScreenMode = "overlay" | "ecran";

export type MinimapperzScreenParams = { mode: MinimapperzScreenMode; eventId: string | null; overlay: OverlayParams; lines: number };

/** The site keeps the board 20 s; reading it as often keeps the screen live without asking more of Minimapperz. */
export const MINIMAPPERZ_SCREEN_REFRESH_MS = 20 * 1000;

const EVENT_ID_PATTERN = /^[A-Za-z0-9_-]{1,64}$/;
const scoreFormatter = new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 1 });
const timeFormatter = new Intl.DateTimeFormat("fr-FR", { timeStyle: "short", timeZone: "Europe/Paris" });

/** Story 49.4: the screen's parameters - `event` (required), `mode=ecran` for the LAN screen, `lines` on the overlay. */
export function parseMinimapperzScreenParams(sp: URLSearchParams): MinimapperzScreenParams {
  const mode: MinimapperzScreenMode = sp.get("mode") === "ecran" ? "ecran" : "overlay";
  const rawEvent = sp.get("event");
  const overlay = parseOverlayParams(sp);
  const lines = Number(sp.get("lines"));
  return {
    mode,
    eventId: rawEvent !== null && EVENT_ID_PATTERN.test(rawEvent) ? rawEvent : null,
    overlay: sp.get("pos") === null ? { ...overlay, pos: "top-right" } : overlay,
    lines: Number.isInteger(lines) && lines >= 1 && lines <= 20 ? lines : mode === "ecran" ? 10 : 5,
  };
}

/** The link to paste in OBS (overlay) or to open on the LAN screen. */
export function minimapperzScreenUrl(appUrl: string, options: { eventId: string; mode: MinimapperzScreenMode }): string {
  const params = new URLSearchParams({ event: options.eventId });
  if (options.mode === "ecran") params.set("mode", "ecran");
  return `${appUrl}/o/minimapperz?${params.toString()}`;
}

/** Story 49.4: the Minimapperz ranking of the event on stream (OBS browser source) or on the LAN screen. */
export function MinimapperzScreen({ params }: { params: MinimapperzScreenParams }) {
  const { data } = useQuery({
    enabled: params.eventId !== null,
    queryKey: ["minimapperz-board", params.eventId],
    queryFn: async () => (params.eventId === null ? null : ((await fetchMinimapperzBoard(params.eventId)) ?? Promise.reject(new Error("board read failed")))),
    staleTime: MINIMAPPERZ_SCREEN_REFRESH_MS,
    refetchInterval: MINIMAPPERZ_SCREEN_REFRESH_MS,
    // A failed read keeps the board on air; the next interval tries again.
    retry: false,
  });

  // The overlay composites over the game capture: a transparent document, or the chroma key asked for.
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

  const board = data ?? null;
  if (params.mode === "ecran") return <MinimapperzWall board={board} lines={params.lines} />;
  return <MinimapperzOverlay board={board} lines={params.lines} params={params.overlay} />;
}

/** A compact ranking anchored where the streamer wants it; nothing at all while there is nothing to show. */
export function MinimapperzOverlay({ board, lines, params }: { board: MinimapperzBoard | null; lines: number; params: OverlayParams }) {
  if (board === null || board.status !== "ok" || board.ranking.length === 0) return null;
  return (
    <div className={cn("fixed inset-0 flex p-6", posToContainerClasses(params.pos))}>
      <div
        className="grid w-80 gap-2 rounded-2xl bg-black/70 p-4 text-white shadow-lg"
        // Dynamic values Tailwind cannot express: the streamer's scale, grown away from the anchored edge.
        style={{ transform: `scale(${params.scale})`, transformOrigin: posToTransformOrigin(params.pos) }}
      >
        <p className="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-white/80">
          <Compass aria-hidden className="size-4" />
          Minimapperz
        </p>
        <RankingList className="text-base" lines={board.ranking.slice(0, lines)} />
      </div>
    </div>
  );
}

/** The LAN screen: the ranking big enough for the back of the room, and the last games beside it. */
export function MinimapperzWall({ board, lines }: { board: MinimapperzBoard | null; lines: number }) {
  return (
    <main className="flex min-h-dvh flex-col gap-10 bg-background p-10 text-foreground">
      <header className="flex items-center gap-4">
        <Compass aria-hidden className="size-12 text-accent-text" />
        <div>
          <h1 className="font-heading text-5xl font-bold md:text-6xl">Classement Minimapperz</h1>
          {board !== null ? <p className="text-2xl text-muted-foreground">{board.eventTitle}</p> : null}
        </div>
      </header>
      {board === null || board.status === "unavailable" ? (
        <p className="text-3xl text-muted-foreground">Le classement s&apos;affichera ici dès le début de la LAN.</p>
      ) : board.status === "unreachable" ? (
        <p className="text-3xl text-muted-foreground">Minimapperz ne répond pas, le classement revient dans un instant.</p>
      ) : board.ranking.length === 0 ? (
        <p className="text-3xl text-muted-foreground">Aucune partie terminée pour l&apos;instant. À vous de jouer !</p>
      ) : (
        <div className="grid flex-1 gap-10 lg:grid-cols-[2fr_1fr]">
          <RankingList className="text-3xl md:text-4xl" lines={board.ranking.slice(0, lines)} />
          {board.recent.length > 0 ? (
            <section aria-labelledby="minimapperz-recent" className="grid content-start gap-4">
              <h2 className="font-heading text-3xl font-semibold" id="minimapperz-recent">
                Dernières parties
              </h2>
              <ul className="grid gap-3 text-2xl">
                {board.recent.map((game) => (
                  <li className="flex items-baseline justify-between gap-4 rounded-xl border border-border px-4 py-3" key={game.id}>
                    <span className="text-muted-foreground">{timeFormatter.format(new Date(game.playedAt))}</span>
                    <span className="flex items-center gap-2 truncate font-semibold">
                      {game.winner !== null ? <Crown aria-hidden className="size-6 shrink-0 text-accent-text" /> : null}
                      {game.winner ?? `${game.players} joueur${game.players > 1 ? "s" : ""}`}
                    </span>
                  </li>
                ))}
              </ul>
            </section>
          ) : null}
        </div>
      )}
    </main>
  );
}

function RankingList({ lines, className }: { lines: MinimapperzBoardLine[]; className: string }) {
  return (
    <ol className={cn("grid content-start gap-2", className)}>
      {lines.map((line) => (
        <li className="flex items-baseline gap-3" key={`${line.rank}-${line.name}`}>
          <span className={cn("w-[2ch] shrink-0 text-right font-bold tabular-nums", line.rank === 1 ? "text-accent-text" : "opacity-70")}>{line.rank}</span>
          <span className="min-w-0 flex-1 truncate font-semibold">{line.name}</span>
          <span className="shrink-0 tabular-nums">{scoreFormatter.format(line.score)}</span>
        </li>
      ))}
    </ol>
  );
}
