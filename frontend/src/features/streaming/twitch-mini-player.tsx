"use client";

import Script from "next/script";
import { startTransition, useCallback, useEffect, useLayoutEffect, useRef, useState } from "react";
import { Maximize2, X } from "lucide-react";
import Link from "next/link";
import { env } from "@/lib/env";
import { useTwitchStatus } from "@/hooks/use-twitch-status";
import { useTwitchPlayerContext } from "./twitch-player-context";

declare global {
    interface Window {
        Twitch?: {
            Embed: new (
                id: string,
                options: {
                    channel: string;
                    parent: string[];
                    autoplay?: boolean;
                    muted?: boolean;
                    layout?: "video" | "video-with-chat";
                    width?: string | number;
                    height?: string | number;
                }
            ) => TwitchEmbed;
        };
    }
}

type TwitchEmbed = {
    getPlayer: () => TwitchPlayer;
    addEventListener: (event: string, handler: () => void) => void;
};

type TwitchPlayer = {
    pause: () => void;
    play: () => void;
};

type Rect = { top: number; left: number; width: number; height: number };
type MiniState = { x: number; y: number; w: number };
type Handle = "nw" | "n" | "ne" | "e" | "se" | "s" | "sw" | "w";

const RATIO = 9 / 16;
const HEADER_H = 36;
const DEFAULT_W = 320;
const MIN_W = 200;
const EMBED_ID = "twitch-persistent-embed";

function videoH(w: number) {
    return Math.round(w * RATIO);
}

const cornerHandles: Handle[] = ["nw", "ne", "sw", "se"];
const cornerPos: Record<string, React.CSSProperties> = {
    nw: { top: 0, left: 0, cursor: "nw-resize" },
    ne: { top: 0, right: 0, cursor: "ne-resize" },
    sw: { bottom: 0, left: 0, cursor: "sw-resize" },
    se: { bottom: 0, right: 0, cursor: "se-resize" },
};

const sideHandles: Handle[] = ["n", "s", "e", "w"];
const sidePos: Record<string, React.CSSProperties> = {
    n: { top: 0, left: 20, right: 20, height: 8, cursor: "n-resize" },
    s: { bottom: 0, left: 20, right: 20, height: 8, cursor: "s-resize" },
    e: { top: 20, right: 0, bottom: 20, width: 8, cursor: "e-resize" },
    w: { top: 20, left: 0, bottom: 20, width: 8, cursor: "w-resize" },
};

export function TwitchPersistentPlayer() {
    const { placeholderEl, mainPlayerMounted, miniPlayerDismissed, dismissMiniPlayer, resetDismissed } = useTwitchPlayerContext();
    const { live } = useTwitchStatus();
    const [hostnameReady, setHostnameReady] = useState(false);
    const [inlineRect, setInlineRect] = useState<Rect | null>(null);
    const [miniState, setMiniState] = useState<MiniState | null>(null);
    const [placeholderInView, setPlaceholderInView] = useState(true);
    const [isPlaying, setIsPlaying] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);
    const hostnameRef = useRef("");
    const playerRef = useRef<TwitchPlayer | null>(null);
    const embedInitialized = useRef(false);

    useEffect(() => {
        hostnameRef.current = window.location.hostname;
        startTransition(() => { setHostnameReady(true); });
    }, []);

    useEffect(() => {
        if (mainPlayerMounted) {
            startTransition(() => {
                setMiniState(null);
                setPlaceholderInView(true);
            });
        }
    }, [mainPlayerMounted]);

    useLayoutEffect(() => {
        if (!mainPlayerMounted || !placeholderEl) {
            // isInline already guards against stale inlineRect when conditions aren't met
            return;
        }
        function sync() {
            if (!placeholderEl) return;
            const r = placeholderEl.getBoundingClientRect();
            setInlineRect({ top: r.top, left: r.left, width: r.width, height: r.height });
        }
        sync();
        const ro = new ResizeObserver(sync);
        ro.observe(placeholderEl);
        window.addEventListener("scroll", sync, { passive: true });
        window.addEventListener("resize", sync);

        const io = new IntersectionObserver(
            ([entry]) => setPlaceholderInView(entry.isIntersecting),
            { threshold: 0 },
        );
        io.observe(placeholderEl);

        return () => {
            ro.disconnect();
            io.disconnect();
            window.removeEventListener("scroll", sync);
            window.removeEventListener("resize", sync);
        };
    }, [mainPlayerMounted, placeholderEl]);

    const initPlayer = useCallback(() => {
        if (embedInitialized.current || !hostnameRef.current || !window.Twitch) return;
        if (!document.getElementById(EMBED_ID)) return;
        embedInitialized.current = true;
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
        });
        embed.addEventListener("playing", () => { setIsPlaying(true); resetDismissed(); });
        embed.addEventListener("pause", () => setIsPlaying(false));
        embed.addEventListener("offline", () => setIsPlaying(false));
    }, [resetDismissed]);

    useEffect(() => {
        if (live && hostnameReady) initPlayer();
    }, [live, hostnameReady, initPlayer]);

    const isInline = mainPlayerMounted && placeholderInView && inlineRect !== null;
    const isMini = !isInline && !miniPlayerDismissed && isPlaying;
    const shouldHide = !isInline && !isMini;

    if (!live || !hostnameReady) return null;

    // --- Direct drag loop (no spring, no bounce) ---
    function startDrag(
        container: HTMLDivElement,
        initX: number, initY: number,
        offsetX: number, offsetY: number,
        w: number,
        onRelease: (x: number, y: number, vx: number, vy: number, moved: boolean) => void,
        dragThreshold = 0,
        startCursorX = 0, startCursorY = 0,
    ) {
        let currentX = initX;
        let currentY = initY;
        let prevX = initX;
        let prevY = initY;
        let vx = 0;
        let vy = 0;
        let moved = dragThreshold === 0;

        container.style.transition = "none";
        container.style.willChange = "left, top";

        function onMouseMove(ev: MouseEvent) {
            if (!moved && Math.hypot(ev.clientX - startCursorX, ev.clientY - startCursorY) > dragThreshold) {
                moved = true;
            }
            if (moved) {
                prevX = currentX;
                prevY = currentY;
                currentX = Math.max(0, Math.min(window.innerWidth - w, ev.clientX - offsetX));
                currentY = Math.max(0, Math.min(window.innerHeight - HEADER_H, ev.clientY - offsetY));
                vx = currentX - prevX;
                vy = currentY - prevY;
                container.style.left = `${currentX}px`;
                container.style.top = `${currentY}px`;
            }
        }

        function onMouseUp() {
            window.removeEventListener("mousemove", onMouseMove);
            window.removeEventListener("mouseup", onMouseUp);
            onRelease(currentX, currentY, vx, vy, moved);
        }

        window.addEventListener("mousemove", onMouseMove);
        window.addEventListener("mouseup", onMouseUp);
    }

    // --- Inertia commit: CSS glide then sync React state ---
    function commitWithInertia(container: HTMLDivElement, x: number, y: number, vx: number, vy: number, w: number) {
        const DURATION = 160;
        const MAX_SLIDE = 80; // plafond en px pour éviter l'effet catapulte sur geste court et rapide
        const easing = "cubic-bezier(0.25, 0.46, 0.45, 0.94)";
        const clamp = (val: number) => Math.sign(val) * Math.min(Math.abs(val), MAX_SLIDE);
        const finalX = Math.max(0, Math.min(window.innerWidth - w, x + clamp(vx * DURATION * 0.18)));
        const finalY = Math.max(0, Math.min(window.innerHeight - HEADER_H, y + clamp(vy * DURATION * 0.18)));
        container.style.transition = `left ${DURATION}ms ${easing}, top ${DURATION}ms ${easing}`;
        container.style.left = `${finalX}px`;
        container.style.top = `${finalY}px`;
        setTimeout(() => {
            container.style.transition = "";
            container.style.willChange = "";
            setMiniState({ x: finalX, y: finalY, w });
        }, DURATION);
    }

    // --- Header drag ---
    function onDragStart(e: React.MouseEvent) {
        e.preventDefault();
        const container = containerRef.current;
        if (!container) return;
        const rect = container.getBoundingClientRect();
        const w = miniState?.w ?? DEFAULT_W;
        startDrag(
            container,
            rect.left, rect.top,
            e.clientX - rect.left, e.clientY - rect.top,
            w,
            (x, y, vx, vy) => commitWithInertia(container, x, y, vx, vy, w),
        );
    }

    // --- Resize (8 handles, 16:9 ratio locked) ---
    function onResizeStart(handle: Handle) {
        return (e: React.MouseEvent) => {
            e.preventDefault();
            e.stopPropagation();
            const container = containerRef.current;
            if (!container) return;
            const rect = container.getBoundingClientRect();
            const startW = rect.width;
            const startVH = rect.height - HEADER_H;
            const startX = e.clientX;
            const startY = e.clientY;
            const anchorRight = rect.right;
            const anchorBottom = rect.bottom;

            function onMove(ev: MouseEvent) {
                const dx = ev.clientX - startX;
                const dy = ev.clientY - startY;

                let newW: number;
                if (handle === "e" || handle === "ne" || handle === "se") {
                    newW = Math.max(MIN_W, Math.min(startW + dx, window.innerWidth - rect.left));
                } else if (handle === "w" || handle === "nw" || handle === "sw") {
                    newW = Math.max(MIN_W, Math.min(startW - dx, anchorRight));
                } else if (handle === "n") {
                    const clampedVH = Math.max(Math.round(MIN_W * RATIO), Math.min(startVH - dy, anchorBottom - HEADER_H));
                    newW = Math.round(clampedVH / RATIO);
                } else {
                    const clampedVH = Math.max(Math.round(MIN_W * RATIO), Math.min(startVH + dy, window.innerHeight - rect.top - HEADER_H));
                    newW = Math.round(clampedVH / RATIO);
                }

                // Also clamp width so the proportional height doesn't overflow the viewport
                const maxWByHeight = handle === "nw" || handle === "ne"
                    ? Math.round((anchorBottom - HEADER_H) / RATIO)
                    : Math.round((window.innerHeight - rect.top - HEADER_H) / RATIO);
                newW = Math.max(MIN_W, Math.min(newW, maxWByHeight));

                const newVH = videoH(newW);
                const newTotalH = HEADER_H + newVH;

                const newX = (handle === "w" || handle === "nw" || handle === "sw")
                    ? Math.max(0, anchorRight - newW)
                    : Math.min(rect.left, window.innerWidth - newW);

                const newY = (handle === "nw" || handle === "n" || handle === "ne")
                    ? Math.max(0, anchorBottom - newTotalH)
                    : Math.min(rect.top, window.innerHeight - newTotalH);

                setMiniState({ x: newX, y: newY, w: newW });
            }

            function onUp() {
                window.removeEventListener("mousemove", onMove);
                window.removeEventListener("mouseup", onUp);
            }
            window.addEventListener("mousemove", onMove);
            window.addEventListener("mouseup", onUp);
        };
    }

    // --- Styles ---
    const containerStyle: React.CSSProperties = shouldHide
        ? {
            position: "fixed",
            top: -9999,
            left: -9999,
            width: DEFAULT_W,
            height: HEADER_H + videoH(DEFAULT_W),
            visibility: "hidden",
            pointerEvents: "none",
        }
        : isInline && inlineRect
        ? {
            position: "fixed",
            top: inlineRect.top,
            left: inlineRect.left,
            width: inlineRect.width,
            height: inlineRect.height,
            zIndex: 20,
            borderRadius: "0.5rem",
            overflow: "hidden",
        }
        : {
            position: "fixed",
            ...(miniState
                ? { top: miniState.y, left: miniState.x }
                : { bottom: "1rem", right: "1rem" }),
            width: miniState?.w ?? DEFAULT_W,
            zIndex: 50,
            borderRadius: "0.5rem",
            overflow: "hidden",
            border: "1px solid var(--color-border)",
            boxShadow: "0 8px 40px rgba(0,0,0,0.7)",
        };

    return (
        <>
            <Script src="https://embed.twitch.tv/embed/v1.js" strategy="lazyOnload" onLoad={initPlayer} />
            <div ref={containerRef} style={containerStyle}>
                {/* Header - drag handle, hidden in inline or hidden mode */}
                <div
                    className="flex select-none items-center justify-between bg-surface px-3"
                    style={{ display: isInline || shouldHide ? "none" : "flex", height: HEADER_H, cursor: "grab" }}
                    onMouseDown={onDragStart}
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
                            onClick={() => { playerRef.current?.pause(); dismissMiniPlayer(); }}
                        >
                            <X aria-hidden="true" className="size-3.5" />
                        </button>
                    </div>
                </div>

                {/* Embed wrapper - explicit height so Twitch iframe fills it */}
                <div
                    style={{
                        width: "100%",
                        height: isInline ? "100%" : videoH(miniState?.w ?? DEFAULT_W),
                        position: "relative",
                    }}
                >
                    <div id={EMBED_ID} style={{ position: "absolute", inset: 0, overflow: "hidden" }} />
                </div>

                {/* Corner handles */}
                {isMini && cornerHandles.map((handle) => (
                    <div
                        key={handle}
                        aria-hidden="true"
                        style={{ position: "absolute", width: 20, height: 20, zIndex: 10, ...cornerPos[handle] }}
                        onMouseDown={onResizeStart(handle)}
                    />
                ))}

                {/* Side handles */}
                {isMini && sideHandles.map((handle) => (
                    <div
                        key={handle}
                        aria-hidden="true"
                        style={{ position: "absolute", zIndex: 10, ...sidePos[handle] }}
                        onMouseDown={onResizeStart(handle)}
                    />
                ))}
            </div>
        </>
    );
}
