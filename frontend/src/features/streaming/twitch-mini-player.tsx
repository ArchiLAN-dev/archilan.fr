"use client";

import Script from "next/script";
import { useCallback, useEffect, useLayoutEffect, useRef, useState } from "react";
import { Maximize2, X } from "lucide-react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { env } from "@/lib/env";
import { useTwitchStatus } from "@/hooks/use-twitch-status";
import { useTwitchPlayerContext } from "./twitch-player-context";

declare global {
    interface Window {
        Twitch?: {
            Embed: new (id: string, opts: {
                channel: string;
                parent: string[];
                autoplay?: boolean;
                muted?: boolean;
                layout?: "video" | "video-with-chat";
                width?: string | number;
                height?: string | number;
            }) => {
                getPlayer: () => { pause: () => void; play: () => void };
                addEventListener: (event: string, cb: () => void) => void;
            };
        };
    }
}

type Rect    = { top: number; left: number; width: number; height: number };
type MiniPos = { x: number; y: number; w: number };
type Handle  = "nw" | "n" | "ne" | "e" | "se" | "s" | "sw" | "w";

const RATIO    = 9 / 16;
const HEADER_H = 36;
const DEFAULT_W = 320;
const MIN_W     = 200;
const EMBED_ID  = "twitch-persistent-embed";

const videoH = (w: number) => Math.round(w * RATIO);

const CORNER_HANDLES: Handle[] = ["nw", "ne", "sw", "se"];
const CORNER_CSS: Record<string, React.CSSProperties> = {
    nw: { top: 0, left: 0,   cursor: "nw-resize" },
    ne: { top: 0, right: 0,  cursor: "ne-resize" },
    sw: { bottom: 0, left: 0,  cursor: "sw-resize" },
    se: { bottom: 0, right: 0, cursor: "se-resize" },
};
const SIDE_HANDLES: Handle[] = ["n", "s", "e", "w"];
const SIDE_CSS: Record<string, React.CSSProperties> = {
    n: { top: 0,    left: 20, right: 20,           height: 8, cursor: "n-resize" },
    s: { bottom: 0, left: 20, right: 20,           height: 8, cursor: "s-resize" },
    e: { top: 20,   right: 0, bottom: 20,          width: 8,  cursor: "e-resize" },
    w: { top: 20,   left: 0,  bottom: 20,          width: 8,  cursor: "w-resize" },
};

export function TwitchPersistentPlayer() {
    const { live } = useTwitchStatus();
    const { placeholder, dismissed, dismiss, undismiss } = useTwitchPlayerContext();
    const pathname = usePathname();

    const [hostnameReady] = useState(() => typeof window !== "undefined");
    const [inlineRect, setInlineRect] = useState<Rect | null>(null);
    const [inView, setInView]         = useState(false);
    const [miniPos, setMiniPos]       = useState<MiniPos | null>(null);

    const containerRef   = useRef<HTMLDivElement>(null);
    const hostnameRef    = useRef(typeof window !== "undefined" ? window.location.hostname : "");
    const playerRef      = useRef<{ pause: () => void; play: () => void } | null>(null);
    const initializedRef = useRef(false);

    // Nouveau stream détecté → réafficher le mini même si l'user l'avait fermé
    const prevLiveRef = useRef(false);
    useEffect(() => {
        if (!prevLiveRef.current && live) undismiss();
        prevLiveRef.current = live;
    }, [live, undismiss]);

    // Retour sur la home → reset position mini
    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        if (placeholder) setMiniPos(null);
    }, [placeholder]);

    // --- Synchronisation de la position inline sur le placeholder ---
    useLayoutEffect(() => {
        if (!placeholder) {
            // eslint-disable-next-line react-hooks/set-state-in-effect
            setInlineRect(null);
            return;
        }
        const sync = () => {
            const r = placeholder.getBoundingClientRect();
            setInlineRect({ top: r.top, left: r.left, width: r.width, height: r.height });
        };
        sync();
        const ro = new ResizeObserver(sync);
        ro.observe(placeholder);
        window.addEventListener("scroll", sync, { passive: true });
        window.addEventListener("resize", sync);
        const io = new IntersectionObserver(([e]) => setInView(e.isIntersecting), { threshold: 0 });
        io.observe(placeholder);
        return () => {
            ro.disconnect();
            io.disconnect();
            window.removeEventListener("scroll", sync);
            window.removeEventListener("resize", sync);
        };
    }, [placeholder]);

    // --- Logique d'affichage (calculée ici pour servir de garde aux effets ci-dessous) ---
    // inView démarre à false : l'IntersectionObserver corrige dès son premier fire,
    // ce qui évite que le container soit positionné hors-viewport au moment de l'init.
    const isInline = placeholder !== null && inView && inlineRect !== null;
    const onHomePage = pathname === "/";
    const isMini = !isInline && !dismissed && (!onHomePage || placeholder !== null);
    const isHidden = !isInline && !isMini;

    // --- Initialisation de l'embed Twitch (une seule fois) ---
    const initEmbed = useCallback(() => {
        if (initializedRef.current || !hostnameRef.current || !window.Twitch) return;
        if (!document.getElementById(EMBED_ID)) return;
        initializedRef.current = true;
        const embed = new window.Twitch.Embed(EMBED_ID, {
            channel: env.twitchChannelLogin,
            parent: [hostnameRef.current],
            layout: "video",
            autoplay: true,
            muted: true,
            width: "100%",
            height: "100%",
        });
        embed.addEventListener("ready", () => {
            playerRef.current = embed.getPlayer();
            // Déclenche la lecture dès que le player est prêt (l'autoplay peut avoir
            // été bloqué pendant le chargement de l'iframe).
            playerRef.current.play();
        });
    }, []);

    useEffect(() => {
        if (live && hostnameReady) initEmbed();
    }, [live, hostnameReady, initEmbed]);

    // Après une transition inline↔mini le SDK peut mettre en pause ; play() après
    // un court délai rétablit la lecture.
    useEffect(() => {
        if (!live || dismissed) return;
        const timer = setTimeout(() => { playerRef.current?.play(); }, 600);
        return () => clearTimeout(timer);
    }, [placeholder, inView, live, dismissed]);

    if (!live || !hostnameReady) return null;

    // --- Drag ---
    function startDrag(e: React.MouseEvent) {
        e.preventDefault();
        const container = containerRef.current;
        if (!container) return;
        const rect = container.getBoundingClientRect();
        const w    = miniPos?.w ?? DEFAULT_W;
        let x = rect.left, y = rect.top;
        let px = x, py = y, vx = 0, vy = 0;
        container.style.transition  = "none";
        container.style.willChange  = "left, top";
        const ox = e.clientX - rect.left;
        const oy = e.clientY - rect.top;

        const onMove = (ev: MouseEvent) => {
            px = x; py = y;
            x = Math.max(0, Math.min(window.innerWidth  - w,         ev.clientX - ox));
            y = Math.max(0, Math.min(window.innerHeight - HEADER_H,  ev.clientY - oy));
            vx = x - px; vy = y - py;
            container.style.left = `${x}px`;
            container.style.top  = `${y}px`;
        };
        const onUp = () => {
            window.removeEventListener("mousemove", onMove);
            window.removeEventListener("mouseup", onUp);
            // Inertie légère
            const D = 160, MAX = 80, ease = "cubic-bezier(0.25,0.46,0.45,0.94)";
            const clamp = (v: number) => Math.sign(v) * Math.min(Math.abs(v), MAX);
            const fx = Math.max(0, Math.min(window.innerWidth  - w,         x + clamp(vx * D * 0.18)));
            const fy = Math.max(0, Math.min(window.innerHeight - HEADER_H,  y + clamp(vy * D * 0.18)));
            container.style.transition = `left ${D}ms ${ease}, top ${D}ms ${ease}`;
            container.style.left = `${fx}px`;
            container.style.top  = `${fy}px`;
            setTimeout(() => {
                container.style.transition = "";
                container.style.willChange = "";
                setMiniPos({ x: fx, y: fy, w });
            }, D);
        };
        window.addEventListener("mousemove", onMove);
        window.addEventListener("mouseup", onUp);
    }

    // --- Resize (8 poignées, ratio 16:9 verrouillé) ---
    function startResize(handle: Handle) {
        return (e: React.MouseEvent) => {
            e.preventDefault();
            e.stopPropagation();
            const container = containerRef.current;
            if (!container) return;
            const rect = container.getBoundingClientRect();
            const sw = rect.width, svh = rect.height - HEADER_H;
            const sx = e.clientX, sy = e.clientY;
            const ar = rect.right, ab = rect.bottom;

            const onMove = (ev: MouseEvent) => {
                const dx = ev.clientX - sx, dy = ev.clientY - sy;
                let nw: number;
                if (handle === "e" || handle === "ne" || handle === "se")
                    nw = Math.max(MIN_W, Math.min(sw + dx, window.innerWidth - rect.left));
                else if (handle === "w" || handle === "nw" || handle === "sw")
                    nw = Math.max(MIN_W, Math.min(sw - dx, ar));
                else if (handle === "n") {
                    const cvh = Math.max(Math.round(MIN_W * RATIO), Math.min(svh - dy, ab - HEADER_H));
                    nw = Math.round(cvh / RATIO);
                } else {
                    const cvh = Math.max(Math.round(MIN_W * RATIO), Math.min(svh + dy, window.innerHeight - rect.top - HEADER_H));
                    nw = Math.round(cvh / RATIO);
                }
                const maxH = (handle === "nw" || handle === "ne")
                    ? Math.round((ab - HEADER_H) / RATIO)
                    : Math.round((window.innerHeight - rect.top - HEADER_H) / RATIO);
                nw = Math.max(MIN_W, Math.min(nw, maxH));
                const nh = HEADER_H + videoH(nw);
                const nx = (handle === "w" || handle === "nw" || handle === "sw")
                    ? Math.max(0, ar - nw)
                    : Math.min(rect.left, window.innerWidth - nw);
                const ny = (handle === "nw" || handle === "n" || handle === "ne")
                    ? Math.max(0, ab - nh)
                    : Math.min(rect.top, window.innerHeight - nh);
                setMiniPos({ x: nx, y: ny, w: nw });
            };
            const onUp = () => {
                window.removeEventListener("mousemove", onMove);
                window.removeEventListener("mouseup", onUp);
            };
            window.addEventListener("mousemove", onMove);
            window.addEventListener("mouseup", onUp);
        };
    }

    // --- Styles ---
    const containerStyle: React.CSSProperties = isHidden
        ? {
            // clip-path cache visuellement le container sans opacity:0 ni visibility:hidden.
            // Chrome ne throttle pas l'iframe et Twitch accepte l'autoplay.
            position: "fixed", top: 0, left: 0,
            width: DEFAULT_W, height: HEADER_H + videoH(DEFAULT_W),
            clipPath: "inset(100%)",
            pointerEvents: "none", zIndex: -1,
        }
        : isInline && inlineRect
        ? {
            position: "fixed",
            top: inlineRect.top, left: inlineRect.left,
            width: inlineRect.width, height: inlineRect.height,
            zIndex: 20, borderRadius: "0.5rem", overflow: "hidden",
        }
        : {
            position: "fixed",
            ...(miniPos ? { top: miniPos.y, left: miniPos.x } : { bottom: "1rem", right: "1rem" }),
            width: miniPos?.w ?? DEFAULT_W,
            zIndex: 50, borderRadius: "0.5rem", overflow: "hidden",
            border: "1px solid var(--color-border)",
            boxShadow: "0 8px 40px rgba(0,0,0,0.7)",
        };

    return (
        <>
            <Script src="https://embed.twitch.tv/embed/v1.js" strategy="lazyOnload" onLoad={initEmbed} />
            <div ref={containerRef} style={containerStyle}>

                {/* Barre de titre (mini uniquement) */}
                <div
                    className="flex select-none items-center justify-between bg-surface px-3"
                    style={{ display: isMini ? "flex" : "none", height: HEADER_H, cursor: "grab" }}
                    onMouseDown={startDrag}
                >
                    <div className="flex items-center gap-2">
                        <span aria-hidden="true" className="relative flex size-2 shrink-0">
                            <svg className="absolute inset-0 animate-ping" fill="none" viewBox="0 0 8 8">
                                <circle cx="4" cy="4" fill="#991b1b" opacity="0.55" r="3.5" />
                            </svg>
                            <svg className="relative" fill="none" viewBox="0 0 8 8">
                                <circle cx="4" cy="4" fill="#991b1b" r="3.5" />
                            </svg>
                        </span>
                        <span className="text-xs font-semibold text-foreground">ArchiLAN en direct</span>
                    </div>
                    <div className="flex items-center" onMouseDown={(e) => e.stopPropagation()}>
                        <Link
                            aria-label="Retourner au lecteur principal"
                            className="inline-flex size-7 items-center justify-center rounded text-muted-foreground transition-colors hover:text-foreground"
                            href="/#live-stream-heading"
                        >
                            <Maximize2 aria-hidden="true" className="size-3.5" />
                        </Link>
                        <button
                            aria-label="Fermer le mini lecteur"
                            className="inline-flex size-7 items-center justify-center rounded text-muted-foreground transition-colors hover:text-foreground"
                            type="button"
                            onClick={() => { playerRef.current?.pause(); dismiss(); }}
                        >
                            <X aria-hidden="true" className="size-3.5" />
                        </button>
                    </div>
                </div>

                {/* Conteneur de l'embed */}
                <div style={{
                    width: "100%",
                    height: isInline ? "100%" : videoH(miniPos?.w ?? DEFAULT_W),
                    position: "relative",
                }}>
                    <div id={EMBED_ID} style={{ position: "absolute", inset: 0, overflow: "hidden" }} />
                </div>

                {/* Poignées de redimensionnement (mini uniquement) */}
                {isMini && CORNER_HANDLES.map((h) => (
                    <div key={h} aria-hidden="true"
                        style={{ position: "absolute", width: 20, height: 20, zIndex: 10, ...CORNER_CSS[h] }}
                        onMouseDown={startResize(h)}
                    />
                ))}
                {isMini && SIDE_HANDLES.map((h) => (
                    <div key={h} aria-hidden="true"
                        style={{ position: "absolute", zIndex: 10, ...SIDE_CSS[h] }}
                        onMouseDown={startResize(h)}
                    />
                ))}
            </div>
        </>
    );
}
