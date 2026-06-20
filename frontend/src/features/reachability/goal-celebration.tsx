"use client";

import { Sparkles, X } from "lucide-react";
import { type CSSProperties, useEffect, useState } from "react";
import { GravWave } from "./grav-wave";
import { PixelTrophy } from "./pixel-trophy";
import { useChiptune } from "./use-chiptune";

// ─── Particle types ───────────────────────────────────────────────────────────

const CONFETTI_COLORS = [
    "#FFD700", "#FFA500", "#FF6B35", "#E0246A",
    "#c084fc", "#38bdf8", "#34d399", "#f472b6",
    "#a78bfa", "#67e8f9", "#DCEDC1", "#FFD3B6",
];

const SHOT_COLORS = ["#c084fc", "#67e8f9", "#f472b6", "#a78bfa", "#38bdf8"];

type Piece = { id: number; left: number; color: string; w: number; h: number; round: boolean; delay: number; dur: number; rot: number; drift: number };
type Spark = { id: number; left: number; top: number; size: number; delay: number; dur: number };
type Shot  = { id: number; left: number; top: number; angle: number; len: number; delay: number; dur: number; dist: number; color: string };

function rand(min: number, max: number) {
    return min + Math.random() * (max - min);
}

function generatePieces(n: number): Piece[] {
    return Array.from({ length: n }, (_, i) => ({
        id: i, left: rand(0, 100),
        color: CONFETTI_COLORS[Math.floor(Math.random() * CONFETTI_COLORS.length)],
        w: rand(5, 14), h: rand(6, 16),
        round: Math.random() > 0.65,
        delay: rand(0, 4), dur: rand(3.5, 7),
        rot: rand(-540, 540), drift: rand(-120, 120),
    }));
}

function generateSparks(n: number): Spark[] {
    return Array.from({ length: n }, (_, i) => ({
        id: i, left: rand(5, 95), top: rand(10, 85),
        size: rand(4, 10), delay: rand(0, 3), dur: rand(1.5, 3),
    }));
}

function generateShots(n: number): Shot[] {
    return Array.from({ length: n }, (_, i) => {
        const fromTop = Math.random() > 0.4;
        return {
            id: i,
            left: fromTop ? rand(-15, 108) : rand(-28, -5),
            top:  fromTop ? rand(-18, -4)  : rand(-12, 92),
            angle: rand(22, 52),
            len: rand(160, 320),
            delay: rand(0, 9),
            dur: rand(1.5, 3.0),
            dist: rand(105, 160), // vw - guarantees full-screen crossing
            color: SHOT_COLORS[Math.floor(Math.random() * SHOT_COLORS.length)],
        };
    });
}

// ─── Keyframes ────────────────────────────────────────────────────────────────

const GC_STYLES = `
  @keyframes gc-fall {
    0%   { transform: translateY(-8vh) translateX(0) rotate(0deg); opacity: 1; }
    80%  { opacity: 0.8; }
    100% { transform: translateY(108vh) translateX(var(--drift)) rotate(var(--rot)); opacity: 0; }
  }
  @keyframes gc-spark {
    0%   { transform: scale(0) rotate(0deg); opacity: 0; }
    30%  { transform: scale(1.3) rotate(180deg); opacity: 1; }
    70%  { opacity: 0.7; }
    100% { transform: scale(0) rotate(360deg); opacity: 0; }
  }
  @keyframes gc-shoot {
    0%   { opacity: 0; transform: rotate(var(--angle)) translateX(0); }
    7%   { opacity: 1; }
    80%  { opacity: 0.8; }
    100% { opacity: 0; transform: rotate(var(--angle)) translateX(var(--dist)); }
  }
  @keyframes gc-card-in {
    0%   { transform: scale(0.45) translateY(100px) rotate(-3deg); opacity: 0; }
    55%  { transform: scale(1.06) translateY(-10px) rotate(0.5deg); opacity: 1; }
    75%  { transform: scale(0.98) translateY(4px); }
    100% { transform: scale(1) translateY(0) rotate(0deg); opacity: 1; }
  }
  @keyframes gc-card-out {
    0%   { transform: scale(1); opacity: 1; }
    100% { transform: scale(0.85) translateY(40px); opacity: 0; }
  }
  @keyframes gc-trophy {
    0%,100% { transform: scale(1) rotate(-5deg) translateY(0); }
    50%     { transform: scale(1.18) rotate(5deg) translateY(-10px); }
  }
  @keyframes gc-shimmer {
    0%   { background-position: -300% center; }
    100% { background-position: 300% center; }
  }
  @keyframes gc-bg-pulse {
    0%,100% { opacity: 0.88; }
    50%     { opacity: 1; }
  }
  @keyframes gc-fade-in  { from { opacity: 0; } to { opacity: 1; } }
  @keyframes gc-fade-out { from { opacity: 1; } to { opacity: 0; } }
  @keyframes gc-flash {
    0%   { opacity: 0.65; }
    100% { opacity: 0; pointer-events: none; }
  }
  @keyframes gc-border-glow {
    0%,100% { background-position: 0% 50%; }
    50%     { background-position: 100% 50%; }
  }
  @keyframes gc-target-spin {
    0%   { transform: rotate(0deg); }
    30%  { transform: rotate(360deg); }
    100% { transform: rotate(360deg); }
  }
  @keyframes gc-corner-in {
    0%   { transform: translate(var(--cx), var(--cy)); opacity: 0; }
    12%  { opacity: 1; }
    100% { transform: translate(var(--nx), var(--ny)); opacity: 1; }
  }
  @keyframes gc-corner-pulse {
    0%,100% { transform: translate(var(--nx), var(--ny)); filter: drop-shadow(0 0 4px var(--cc)); opacity: 0.75; }
    50%     { transform: translate(var(--fx), var(--fy)); filter: drop-shadow(0 0 11px var(--cc)); opacity: 1; }
  }
  @keyframes gc-glitch {
    0%,80%,100% { transform: translate(0); filter: none; }
    82% { transform: translate(-5px, 2px); filter: hue-rotate(50deg) brightness(1.3); }
    84% { transform: translate(5px, -2px); filter: hue-rotate(-50deg) brightness(1.2); }
    86% { transform: translate(-2px, 0); filter: hue-rotate(20deg); }
    88% { transform: translate(0); filter: none; }
  }
  @keyframes gc-starburst {
    from { transform: rotate(0deg) scale(1); }
    50%  { transform: rotate(180deg) scale(1.08); }
    to   { transform: rotate(360deg) scale(1); }
  }
  @keyframes gc-title-in {
    0%   { transform: scaleX(0) translateY(-20px); opacity: 0; letter-spacing: 0.8em; }
    60%  { transform: scaleX(1.05); opacity: 1; }
    100% { transform: scaleX(1) translateY(0); opacity: 1; }
  }
  @keyframes gc-scanline {
    from { background-position: 0 0; }
    to   { background-position: 0 6px; }
  }
  @keyframes gc-perfect {
    0%,100% { border-color: #a855f780; color: #c084fc; box-shadow: 0 0 14px #a855f740; }
    25%     { border-color: #06b6d480; color: #67e8f9; box-shadow: 0 0 14px #06b6d440; }
    50%     { border-color: #f59e0b80; color: #fbbf24; box-shadow: 0 0 14px #f59e0b40; }
    75%     { border-color: #e879f980; color: #f0abfc; box-shadow: 0 0 14px #e879f940; }
  }
  @keyframes gc-perfect-text {
    0%,100% { color: #c084fc; text-shadow: 0 0 6px #c084fc, 0 0 18px #a855f760; }
    25%     { color: #67e8f9; text-shadow: 0 0 6px #67e8f9, 0 0 18px #06b6d460; }
    50%     { color: #fbbf24; text-shadow: 0 0 6px #fbbf24, 0 0 18px #f59e0b60; }
    75%     { color: #f0abfc; text-shadow: 0 0 6px #f0abfc, 0 0 18px #e879f960; }
  }
`;

// ─── Main component ───────────────────────────────────────────────────────────

export function GoalCelebration({
    slotName,
    playerAlias,
    gameName,
    checksPercent,
    itemsPercent,
    onDismiss,
    bare = false,
}: {
    slotName: string;
    playerAlias?: string;
    gameName: string;
    checksPercent: number;
    itemsPercent: number;
    onDismiss: () => void;
    // When true (OBS overlay), drop the opaque dark backdrop and the white flash so only the card +
    // particles render over a transparent page. Modal usages (in-app) leave this false.
    bare?: boolean;
}) {
    const [pieces] = useState<Piece[]>(() => generatePieces(100));
    const [sparks] = useState<Spark[]>(() => generateSparks(24));
    const [shots] = useState<Shot[]>(() => generateShots(12));
    const [mounted, setMounted] = useState(false);
    const [leaving, setLeaving] = useState(false);

    useChiptune();

    useEffect(() => {
        const raf = requestAnimationFrame(() => setMounted(true));
        return () => cancelAnimationFrame(raf);
    }, []);

    const displayName = playerAlias ?? slotName;
    const showSlotName = playerAlias != null && playerAlias !== slotName;
    const isPerfect = checksPercent >= 100 && itemsPercent >= 100;

    function dismiss() {
        setLeaving(true);
        setTimeout(onDismiss, 500);
    }

    return (
        <>
            <style>{GC_STYLES}</style>

            {/* Backdrop */}
            <div
                aria-modal="true"
                className="fixed inset-0 z-50 overflow-hidden"
                role="dialog"
                style={{
                    animation: leaving
                        ? "gc-fade-out 0.5s ease forwards"
                        : mounted ? "gc-fade-in 0.35s ease forwards" : undefined,
                    opacity: mounted ? undefined : 0,
                }}
            >
                {/* Deep dark background - omitted in bare/overlay mode so the page stays transparent */}
                {!bare && (
                    <div
                        className="absolute inset-0"
                        style={{
                            background: "radial-gradient(ellipse 90% 80% at 50% 40%, #130926 0%, #07050f 55%, #020008 100%)",
                            animation: "gc-bg-pulse 3s ease-in-out infinite",
                        }}
                    />
                )}

                {/* Gravitational wave - transparent WebGL canvas. A full-screen background field, so it
                    is dropped in bare/overlay mode (keep only the card + foreground particles). */}
                {!bare && <GravWave />}

                {/* Screen flash on open - omitted in bare/overlay mode (it briefly paints the page) */}
                {mounted && !bare && (
                    <div
                        aria-hidden="true"
                        className="pointer-events-none absolute inset-0 bg-white"
                        style={{ animation: "gc-flash 0.45s ease forwards" }}
                    />
                )}

                {/* Sparks */}
                <div aria-hidden="true" className="pointer-events-none absolute inset-0">
                    {sparks.map((s) => (
                        <div
                            key={s.id}
                            className="absolute"
                            style={{
                                left: `${s.left}%`, top: `${s.top}%`,
                                width: s.size, height: s.size,
                                background: `radial-gradient(circle, ${s.id % 2 === 0 ? "#c084fc, #7c3aed" : "#67e8f9, #0284c7"})`,
                                borderRadius: "50%",
                                animation: `gc-spark ${s.dur}s ${s.delay}s ease-in-out infinite`,
                            }}
                        />
                    ))}
                </div>

                {/* Shooting stars */}
                <div aria-hidden="true" className="pointer-events-none absolute inset-0">
                    {shots.map((s) => (
                        <div
                            key={s.id}
                            className="absolute"
                            style={{
                                left: `${s.left}%`, top: `${s.top}%`,
                                width: s.len + 12, height: 10,
                                transformOrigin: "left center",
                                "--angle": `${s.angle}deg`,
                                "--dist": `${s.dist}vw`,
                                animation: `gc-shoot ${s.dur}s ${s.delay}s ease-in infinite`,
                                animationFillMode: "both",
                            } as CSSProperties}
                        >
                            <div style={{
                                position: "absolute", top: 4, left: 0,
                                width: s.len, height: 2,
                                background: `linear-gradient(to right, transparent 0%, ${s.color}30 18%, ${s.color}99 60%, ${s.color} 84%, white 100%)`,
                                borderRadius: "0 1px 1px 0",
                            }} />
                            <div style={{
                                position: "absolute", top: 0, left: s.len - 2,
                                width: 14, height: 10,
                                background: `radial-gradient(ellipse at 25% 50%, white 0%, ${s.color} 42%, transparent 100%)`,
                                filter: "blur(1.5px)",
                                boxShadow: `0 0 8px 2px ${s.color}cc`,
                                borderRadius: "50%",
                            }} />
                        </div>
                    ))}
                </div>

                {/* Central card */}
                <div className="absolute inset-0 flex items-center justify-center p-4">
                    {/* Animation + sizing wrapper */}
                    <div
                        className="relative w-full max-w-lg"
                        style={{
                            animation: leaving
                                ? "gc-card-out 0.4s ease forwards"
                                : mounted
                                    ? "gc-card-in 0.7s cubic-bezier(0.34,1.56,0.64,1) forwards"
                                    : undefined,
                            opacity: mounted ? undefined : 0,
                        }}
                    >
                        {/* Gradient border frame - overflow:hidden guarantees border is always above inner bg */}
                        <div
                            style={{
                                padding: "2px",
                                borderRadius: "1rem",
                                overflow: "hidden",
                                background: "linear-gradient(135deg, #a855f7, #06b6d4, #f59e0b, #e879f9, #a855f7)",
                                backgroundSize: "400% 400%",
                                boxShadow: "0 0 55px rgba(168,85,247,0.22), 0 0 110px rgba(6,182,212,0.1), 0 30px 65px rgba(0,0,0,0.85)",
                                animation: "gc-border-glow 5s ease-in-out 0.8s infinite",
                            }}
                        >
                            {/* Top center bar */}
                            <div aria-hidden="true" style={{
                                position: "absolute", left: "50%", top: 0,
                                transform: "translateX(-50%)",
                                width: "40%", height: "2px",
                                background: "linear-gradient(90deg, transparent, #67e8f9cc, #c084fccc, #67e8f9cc, transparent)",
                                boxShadow: "0 0 10px #67e8f9, 0 0 28px #c084fc80",
                            }} />
                            {/* Bottom center bar */}
                            <div aria-hidden="true" style={{
                                position: "absolute", left: "50%", bottom: 0,
                                transform: "translateX(-50%)",
                                width: "22%", height: "2px",
                                background: "linear-gradient(90deg, transparent, #f59e0bcc, transparent)",
                                boxShadow: "0 0 8px #f59e0b80",
                            }} />

                            {/* Inner dark surface - radius = frame_radius(16px) - padding(2px) = 14px */}
                            <div className="relative bg-[#05030c] px-8 pb-10 pt-8 text-center backdrop-blur-xl" style={{ borderRadius: 14 }}>
                            {/* Scanlines */}
                            <div
                                aria-hidden="true"
                                className="pointer-events-none absolute inset-0"
                                style={{
                                    backgroundImage: "repeating-linear-gradient(0deg, transparent, transparent 2px, rgba(168,85,247,0.025) 2px, rgba(168,85,247,0.025) 3px)",
                                    animation: "gc-scanline 6s linear infinite",
                                }}
                            />

                        {/* Header */}
                        <div className="mb-6">
                            <p className="mb-2 text-[10px] font-bold uppercase tracking-[0.6em] text-purple-400/55">
                                - Objectif atteint -
                            </p>
                            <h1
                                className="font-heading text-6xl font-black uppercase leading-none"
                                style={{
                                    backgroundImage: "linear-gradient(135deg, #c084fc 0%, #67e8f9 45%, #c084fc 100%)",
                                    backgroundSize: "200% auto",
                                    WebkitBackgroundClip: "text",
                                    backgroundClip: "text",
                                    color: "transparent",
                                    animation: mounted
                                        ? "gc-title-in 0.6s 0.15s cubic-bezier(0.34,1.56,0.64,1) both, gc-shimmer 4s 0.8s linear infinite, gc-glitch 6s 2s infinite"
                                        : undefined,
                                }}
                            >
                                VICTOIRE
                            </h1>
                        </div>

                        {/* Trophy */}
                        <div className="relative mx-auto mb-5 flex size-24 items-center justify-center">
                            <div
                                aria-hidden="true"
                                className="absolute"
                                style={{
                                    width: 190, height: 190,
                                    background: "conic-gradient(from 0deg, transparent 0deg, rgba(168,85,247,0.18) 8deg, transparent 16deg, transparent 28deg, rgba(6,182,212,0.18) 36deg, transparent 44deg, transparent 56deg, rgba(168,85,247,0.18) 64deg, transparent 72deg, transparent 84deg, rgba(251,191,36,0.18) 92deg, transparent 100deg, transparent 112deg, rgba(168,85,247,0.18) 120deg, transparent 128deg, transparent 140deg, rgba(6,182,212,0.18) 148deg, transparent 156deg, transparent 168deg, rgba(168,85,247,0.18) 176deg, transparent 184deg, transparent 196deg, rgba(251,191,36,0.18) 204deg, transparent 212deg, transparent 224deg, rgba(168,85,247,0.18) 232deg, transparent 240deg, transparent 252deg, rgba(6,182,212,0.18) 260deg, transparent 268deg, transparent 280deg, rgba(168,85,247,0.18) 288deg, transparent 296deg, transparent 308deg, rgba(251,191,36,0.18) 316deg, transparent 324deg, transparent 336deg, rgba(168,85,247,0.18) 344deg, transparent 352deg, transparent 360deg)",
                                    borderRadius: "50%",
                                    animation: "gc-starburst 7s linear infinite",
                                }}
                            />
                            <div
                                style={{
                                    animation: mounted ? "gc-trophy 1.8s ease-in-out 0.8s infinite" : undefined,
                                    filter: "drop-shadow(0 0 8px #f59e0b) drop-shadow(0 0 20px rgba(245,158,11,0.5)) drop-shadow(0 0 45px rgba(6,182,212,0.3))",
                                }}
                            >
                                <PixelTrophy size={6} />
                            </div>
                        </div>

                        {/* Player name */}
                        <h2
                            className="mb-1 font-heading text-5xl font-black leading-tight"
                            style={{
                                backgroundImage: "linear-gradient(90deg, #FFD700 0%, #FFA500 20%, #FF6B35 50%, #FFA500 80%, #FFD700 100%)",
                                backgroundSize: "300% auto",
                                WebkitBackgroundClip: "text",
                                backgroundClip: "text",
                                color: "transparent",
                                animation: "gc-shimmer 3s linear infinite",
                                filter: "drop-shadow(0 0 14px rgba(251,191,36,0.45))",
                            }}
                        >
                            {displayName}
                        </h2>

                        {showSlotName ? (
                            <p className="mb-3 text-sm font-semibold tracking-[0.12em] text-white/35">{slotName}</p>
                        ) : (
                            <div className="mb-3" />
                        )}

                        <p className="mb-6 text-xs font-semibold uppercase tracking-[0.25em] text-purple-300/50">
                            {gameName}
                        </p>

                        {/* Progress */}
                        {isPerfect ? (
                            <div className="mx-auto mb-8 flex flex-col items-center gap-2">
                                <div
                                    className="inline-flex items-center gap-2 rounded border bg-white/5 px-6 py-2"
                                    style={{ animation: "gc-perfect 2s ease-in-out infinite" }}
                                >
                                    <Sparkles aria-hidden="true" className="size-3.5" />
                                    <span className="font-mono text-sm font-bold tracking-widest">PARFAIT</span>
                                    <Sparkles aria-hidden="true" className="size-3.5" />
                                </div>
                                <span
                                    className="font-mono text-sm font-semibold tracking-wider"
                                    style={{ animation: "gc-perfect-text 2s ease-in-out 0.5s infinite" }}
                                >
                                    Checks 100% · Items 100%
                                </span>
                            </div>
                        ) : (
                            <div className="mx-auto mb-8 flex items-center justify-center gap-3">
                                <div className="inline-flex items-center gap-2 rounded border border-cyan-500/35 bg-cyan-500/10 px-4 py-2">
                                    <span className="text-xs text-cyan-400/70">Checks</span>
                                    <span className="font-mono text-sm font-bold text-cyan-300">{checksPercent}%</span>
                                </div>
                                <div className="inline-flex items-center gap-2 rounded border border-purple-500/35 bg-purple-500/10 px-4 py-2">
                                    <span className="text-xs text-purple-400/70">Items</span>
                                    <span className="font-mono text-sm font-bold text-purple-300">{itemsPercent}%</span>
                                </div>
                            </div>
                        )}

                        <div>
                            <button
                                className="rounded border-2 border-amber-400/65 bg-gradient-to-r from-amber-500/10 to-orange-500/10 px-12 py-3 text-sm font-bold uppercase tracking-[0.2em] text-amber-300 transition-all hover:border-amber-300 hover:bg-amber-400/20 hover:text-white active:scale-95"
                                onClick={dismiss}
                                type="button"
                            >
                                CONTINUER
                            </button>
                        </div>
                            </div>{/* /inner dark surface */}
                        </div>{/* /gradient border frame */}

                        {/* Orbit ring - after the card in DOM so it's always on top */}
                        <div
                            aria-hidden="true"
                            className="pointer-events-none absolute inset-0"
                            style={{ animation: "gc-target-spin 4s 2s ease-in-out infinite" }}
                        >
                            {(["tl", "tr", "bl", "br"] as const).map((c, i) => {
                                const colors = ["#c084fc", "#67e8f9", "#67e8f9", "#c084fc"];
                                const cx = ["-60vw", "60vw",  "-60vw", "60vw" ][i];
                                const cy = ["-60vh", "-60vh", "60vh",  "60vh" ][i];
                                const nx = ["-5px",  "5px",   "-5px",  "5px"  ][i];
                                const ny = ["-5px",  "-5px",  "5px",   "5px"  ][i];
                                const fx = ["-14px", "14px",  "-14px", "14px" ][i];
                                const fy = ["-14px", "-14px", "14px",  "14px" ][i];
                                return (
                                    <div
                                        key={c}
                                        aria-hidden="true"
                                        className={`pointer-events-none absolute size-8 ${
                                            c === "tl" ? "left-0 top-0 border-l-[3px] border-t-[3px]" :
                                            c === "tr" ? "right-0 top-0 border-r-[3px] border-t-[3px]" :
                                            c === "bl" ? "bottom-0 left-0 border-b-[3px] border-l-[3px]" :
                                                         "bottom-0 right-0 border-b-[3px] border-r-[3px]"
                                        }`}
                                        style={{
                                            borderColor: colors[i],
                                            "--cx": cx, "--cy": cy,
                                            "--nx": nx, "--ny": ny,
                                            "--fx": fx, "--fy": fy,
                                            "--cc": colors[i],
                                            animation: "gc-corner-in 0.45s 0.25s cubic-bezier(0.22,1,0.36,1) both, gc-corner-pulse 2s ease-in-out 0.7s infinite",
                                        } as CSSProperties}
                                    />
                                );
                            })}
                        </div>{/* /orbit ring */}
                    </div>{/* /animation wrapper */}
                </div>

                {/* Close button */}
                <button
                    aria-label="Fermer la célébration"
                    className="absolute right-4 top-4 z-10 rounded-full border border-white/10 bg-white/5 p-2 text-white/40 backdrop-blur-sm transition-colors hover:border-white/20 hover:text-white/80"
                    onClick={dismiss}
                    type="button"
                >
                    <X className="size-4" />
                </button>

                {/* Confetti - premier plan */}
                <div aria-hidden="true" className="pointer-events-none absolute inset-0">
                    {pieces.map((p) => (
                        <div
                            key={p.id}
                            className="absolute top-0"
                            style={{
                                left: `${p.left}%`,
                                width: p.w, height: p.round ? p.w : p.h,
                                backgroundColor: p.color,
                                borderRadius: p.round ? "50%" : "2px",
                                "--rot": `${p.rot}deg`, "--drift": `${p.drift}px`,
                                animation: `gc-fall ${p.dur}s ${p.delay}s ease-in infinite`,
                                animationFillMode: "both",
                            } as CSSProperties}
                        />
                    ))}
                </div>
            </div>
        </>
    );
}
