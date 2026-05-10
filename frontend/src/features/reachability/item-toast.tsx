"use client";

import { AlertTriangle, Package, Sparkles, Star, Wind } from "lucide-react";
import { type CSSProperties } from "react";

type ConfettiPiece = {
  x: number; y: number;
  cx: string; cy: string; cr: string;
  delay: number; dur: number;
  color: string; w: number; h: number;
  round?: boolean;
};

const CONFETTI_FILLER: ConfettiPiece[] = [
  { x: 50,  y: 12, cx: "-55px", cy: "-62px", cr: "-200deg", delay: 0.00, dur: 0.72, color: "#9580f5", w: 7, h: 7 },
  { x: 95,  y: 8,  cx: "-12px", cy: "-70px", cr:  "190deg", delay: 0.04, dur: 0.78, color: "#e89420", w: 5, h: 9 },
  { x: 140, y: 10, cx:  "22px", cy: "-68px", cr:  "220deg", delay: 0.07, dur: 0.70, color: "#1abd8c", w: 8, h: 5 },
  { x: 195, y: 8,  cx:  "56px", cy: "-60px", cr:  "170deg", delay: 0.02, dur: 0.68, color: "#e0246a", w: 6, h: 6, round: true },
  { x: 238, y: 14, cx:  "74px", cy: "-48px", cr:  "280deg", delay: 0.09, dur: 0.74, color: "#9580f5", w: 5, h: 8 },
  { x: 252, y: 28, cx:  "72px", cy: "-18px", cr:  "240deg", delay: 0.05, dur: 0.70, color: "#e89420", w: 8, h: 5 },
  { x: 250, y: 44, cx:  "68px", cy:  "20px", cr: "-280deg", delay: 0.11, dur: 0.76, color: "#7b61ff", w: 6, h: 6, round: true },
  { x: 194, y: 54, cx:  "44px", cy:  "58px", cr: "-180deg", delay: 0.06, dur: 0.72, color: "#1abd8c", w: 7, h: 4 },
  { x: 130, y: 58, cx:   "5px", cy:  "64px", cr:  "260deg", delay: 0.03, dur: 0.78, color: "#e0246a", w: 5, h: 5, round: true },
  { x: 74,  y: 54, cx: "-38px", cy:  "56px", cr: "-230deg", delay: 0.08, dur: 0.74, color: "#e89420", w: 8, h: 5 },
  { x: 22,  y: 44, cx: "-68px", cy:  "28px", cr:  "200deg", delay: 0.07, dur: 0.70, color: "#9580f5", w: 6, h: 6 },
  { x: 18,  y: 28, cx: "-72px", cy:  "-8px", cr: "-210deg", delay: 0.04, dur: 0.76, color: "#1abd8c", w: 5, h: 8 },
  { x: 118, y: 30, cx: "-18px", cy: "-50px", cr: "-190deg", delay: 0.00, dur: 0.58, color: "#ffffff", w: 4, h: 4, round: true },
  { x: 146, y: 28, cx:  "22px", cy: "-46px", cr:  "190deg", delay: 0.05, dur: 0.54, color: "#9580f5", w: 3, h: 3, round: true },
  { x: 108, y: 38, cx: "-28px", cy:  "34px", cr: "-160deg", delay: 0.08, dur: 0.64, color: "#e89420", w: 4, h: 4, round: true },
];

const CONFETTI_PROG: ConfettiPiece[] = [
  { x: 50,  y: 12, cx: "-55px", cy: "-62px", cr: "-200deg", delay: 0.00, dur: 0.72, color: "#e89420", w: 7, h: 7 },
  { x: 95,  y: 8,  cx: "-12px", cy: "-70px", cr:  "190deg", delay: 0.04, dur: 0.78, color: "#ffd700", w: 5, h: 9 },
  { x: 140, y: 10, cx:  "22px", cy: "-68px", cr:  "220deg", delay: 0.07, dur: 0.70, color: "#f5c842", w: 8, h: 5 },
  { x: 195, y: 8,  cx:  "56px", cy: "-60px", cr:  "170deg", delay: 0.02, dur: 0.68, color: "#ffffff", w: 6, h: 6, round: true },
  { x: 238, y: 14, cx:  "74px", cy: "-48px", cr:  "280deg", delay: 0.09, dur: 0.74, color: "#ffd700", w: 5, h: 8 },
  { x: 252, y: 28, cx:  "72px", cy: "-18px", cr:  "240deg", delay: 0.05, dur: 0.70, color: "#e89420", w: 8, h: 5 },
  { x: 250, y: 44, cx:  "68px", cy:  "20px", cr: "-280deg", delay: 0.11, dur: 0.76, color: "#f0a030", w: 6, h: 6, round: true },
  { x: 194, y: 54, cx:  "44px", cy:  "58px", cr: "-180deg", delay: 0.06, dur: 0.72, color: "#e89420", w: 7, h: 4 },
  { x: 130, y: 58, cx:   "5px", cy:  "64px", cr:  "260deg", delay: 0.03, dur: 0.78, color: "#ffffff", w: 5, h: 5, round: true },
  { x: 74,  y: 54, cx: "-38px", cy:  "56px", cr: "-230deg", delay: 0.08, dur: 0.74, color: "#ffd700", w: 8, h: 5 },
  { x: 22,  y: 44, cx: "-68px", cy:  "28px", cr:  "200deg", delay: 0.07, dur: 0.70, color: "#e89420", w: 6, h: 6 },
  { x: 18,  y: 28, cx: "-72px", cy:  "-8px", cr: "-210deg", delay: 0.04, dur: 0.76, color: "#f5c842", w: 5, h: 8 },
  { x: 118, y: 30, cx: "-18px", cy: "-50px", cr: "-190deg", delay: 0.00, dur: 0.58, color: "#ffffff", w: 4, h: 4, round: true },
  { x: 146, y: 28, cx:  "22px", cy: "-46px", cr:  "190deg", delay: 0.05, dur: 0.54, color: "#e89420", w: 3, h: 3, round: true },
  { x: 108, y: 38, cx: "-28px", cy:  "34px", cr: "-160deg", delay: 0.08, dur: 0.64, color: "#ffd700", w: 4, h: 4, round: true },
];

const CONFETTI_USEFUL: ConfettiPiece[] = [
  { x: 50,  y: 12, cx: "-55px", cy: "-62px", cr: "-200deg", delay: 0.00, dur: 0.72, color: "#1abd8c", w: 7, h: 7 },
  { x: 95,  y: 8,  cx: "-12px", cy: "-70px", cr:  "190deg", delay: 0.04, dur: 0.78, color: "#2ad4a0", w: 5, h: 9 },
  { x: 140, y: 10, cx:  "22px", cy: "-68px", cr:  "220deg", delay: 0.07, dur: 0.70, color: "#7befd4", w: 8, h: 5 },
  { x: 195, y: 8,  cx:  "56px", cy: "-60px", cr:  "170deg", delay: 0.02, dur: 0.68, color: "#ffffff", w: 6, h: 6, round: true },
  { x: 238, y: 14, cx:  "74px", cy: "-48px", cr:  "280deg", delay: 0.09, dur: 0.74, color: "#1abd8c", w: 5, h: 8 },
  { x: 252, y: 28, cx:  "72px", cy: "-18px", cr:  "240deg", delay: 0.05, dur: 0.70, color: "#2ad4a0", w: 8, h: 5 },
  { x: 250, y: 44, cx:  "68px", cy:  "20px", cr: "-280deg", delay: 0.11, dur: 0.76, color: "#1abd8c", w: 6, h: 6, round: true },
  { x: 194, y: 54, cx:  "44px", cy:  "58px", cr: "-180deg", delay: 0.06, dur: 0.72, color: "#7befd4", w: 7, h: 4 },
  { x: 130, y: 58, cx:   "5px", cy:  "64px", cr:  "260deg", delay: 0.03, dur: 0.78, color: "#ffffff", w: 5, h: 5, round: true },
  { x: 74,  y: 54, cx: "-38px", cy:  "56px", cr: "-230deg", delay: 0.08, dur: 0.74, color: "#2ad4a0", w: 8, h: 5 },
  { x: 22,  y: 44, cx: "-68px", cy:  "28px", cr:  "200deg", delay: 0.07, dur: 0.70, color: "#1abd8c", w: 6, h: 6 },
  { x: 18,  y: 28, cx: "-72px", cy:  "-8px", cr: "-210deg", delay: 0.04, dur: 0.76, color: "#7befd4", w: 5, h: 8 },
  { x: 118, y: 30, cx: "-18px", cy: "-50px", cr: "-190deg", delay: 0.00, dur: 0.58, color: "#ffffff", w: 4, h: 4, round: true },
  { x: 146, y: 28, cx:  "22px", cy: "-46px", cr:  "190deg", delay: 0.05, dur: 0.54, color: "#1abd8c", w: 3, h: 3, round: true },
  { x: 108, y: 38, cx: "-28px", cy:  "34px", cr: "-160deg", delay: 0.08, dur: 0.64, color: "#2ad4a0", w: 4, h: 4, round: true },
];

const CONFETTI_TRAP: ConfettiPiece[] = [
  { x: 50,  y: 12, cx: "-55px", cy: "-62px", cr: "-200deg", delay: 0.00, dur: 0.72, color: "#c4220c", w: 7, h: 7 },
  { x: 95,  y: 8,  cx: "-12px", cy: "-70px", cr:  "190deg", delay: 0.04, dur: 0.78, color: "#ff6b35", w: 5, h: 9 },
  { x: 140, y: 10, cx:  "22px", cy: "-68px", cr:  "220deg", delay: 0.07, dur: 0.70, color: "#e0246a", w: 8, h: 5 },
  { x: 195, y: 8,  cx:  "56px", cy: "-60px", cr:  "170deg", delay: 0.02, dur: 0.68, color: "#c4220c", w: 6, h: 6, round: true },
  { x: 238, y: 14, cx:  "74px", cy: "-48px", cr:  "280deg", delay: 0.09, dur: 0.74, color: "#ff6b35", w: 5, h: 8 },
  { x: 252, y: 28, cx:  "72px", cy: "-18px", cr:  "240deg", delay: 0.05, dur: 0.70, color: "#c4220c", w: 8, h: 5 },
  { x: 250, y: 44, cx:  "68px", cy:  "20px", cr: "-280deg", delay: 0.11, dur: 0.76, color: "#ff6b35", w: 6, h: 6, round: true },
  { x: 194, y: 54, cx:  "44px", cy:  "58px", cr: "-180deg", delay: 0.06, dur: 0.72, color: "#e0246a", w: 7, h: 4 },
  { x: 130, y: 58, cx:   "5px", cy:  "64px", cr:  "260deg", delay: 0.03, dur: 0.78, color: "#c4220c", w: 5, h: 5, round: true },
  { x: 74,  y: 54, cx: "-38px", cy:  "56px", cr: "-230deg", delay: 0.08, dur: 0.74, color: "#ff6b35", w: 8, h: 5 },
  { x: 22,  y: 44, cx: "-68px", cy:  "28px", cr:  "200deg", delay: 0.07, dur: 0.70, color: "#c4220c", w: 6, h: 6 },
  { x: 18,  y: 28, cx: "-72px", cy:  "-8px", cr: "-210deg", delay: 0.04, dur: 0.76, color: "#e0246a", w: 5, h: 8 },
  { x: 118, y: 30, cx: "-18px", cy: "-50px", cr: "-190deg", delay: 0.00, dur: 0.58, color: "#ffffff", w: 4, h: 4, round: true },
  { x: 146, y: 28, cx:  "22px", cy: "-46px", cr:  "190deg", delay: 0.05, dur: 0.54, color: "#c4220c", w: 3, h: 3, round: true },
  { x: 108, y: 38, cx: "-28px", cy:  "34px", cr: "-160deg", delay: 0.08, dur: 0.64, color: "#ff6b35", w: 4, h: 4, round: true },
];

type ToastVariant = "filler" | "progression" | "useful" | "trap";

function getToastVariant(flags: number): ToastVariant {
  if (flags & 4) return "trap";
  if (flags & 1) return "progression";
  if (flags & 2) return "useful";
  return "filler";
}

const TOAST_THEMES = {
  filler: {
    cardBg: "bg-[#07091f]", cardBorder: "border-accent-text/30",
    glow: "0 0 0 1px rgba(149,128,245,0.1), 0 0 28px rgba(149,128,245,0.35), 0 0 70px rgba(149,128,245,0.12), 0 20px 52px rgba(0,0,0,0.85)",
    bracketColor: "border-accent-text",
    shimmerColor: "rgba(255,255,255,0.07)",
    iconBorder: "border-accent-text/40", iconBg: "bg-accent/30",
    iconGlow: "0 0 18px rgba(149,128,245,0.55), inset 0 1px 0 rgba(255,255,255,0.08)",
    IconComp: Wind, iconColor: "text-accent-text",
    LabelIconComp: Sparkles, label: "Item reçu", labelColor: "text-accent-warm",
    confetti: CONFETTI_FILLER,
  },
  progression: {
    cardBg: "bg-[#140c00]", cardBorder: "border-accent-warm/40",
    glow: "0 0 0 1px rgba(232,148,32,0.15), 0 0 32px rgba(232,148,32,0.5), 0 0 80px rgba(232,148,32,0.2), 0 20px 52px rgba(0,0,0,0.85)",
    bracketColor: "border-accent-warm",
    shimmerColor: "rgba(232,148,32,0.12)",
    iconBorder: "border-accent-warm/40", iconBg: "bg-accent-warm/20",
    iconGlow: "0 0 18px rgba(232,148,32,0.7), inset 0 1px 0 rgba(255,255,255,0.08)",
    IconComp: Star, iconColor: "text-accent-warm",
    LabelIconComp: Star, label: "Progression !", labelColor: "text-accent-warm",
    confetti: CONFETTI_PROG,
  },
  useful: {
    cardBg: "bg-[#02120e]", cardBorder: "border-success/30",
    glow: "0 0 0 1px rgba(26,189,140,0.1), 0 0 28px rgba(26,189,140,0.35), 0 0 70px rgba(26,189,140,0.12), 0 20px 52px rgba(0,0,0,0.85)",
    bracketColor: "border-success",
    shimmerColor: "rgba(26,189,140,0.07)",
    iconBorder: "border-success/40", iconBg: "bg-success/20",
    iconGlow: "0 0 18px rgba(26,189,140,0.55), inset 0 1px 0 rgba(255,255,255,0.08)",
    IconComp: Package, iconColor: "text-success",
    LabelIconComp: Sparkles, label: "Objet utile", labelColor: "text-success",
    confetti: CONFETTI_USEFUL,
  },
  trap: {
    cardBg: "bg-[#120200]", cardBorder: "border-danger/40",
    glow: "0 0 0 1px rgba(196,34,12,0.15), 0 0 28px rgba(196,34,12,0.4), 0 0 70px rgba(196,34,12,0.15), 0 20px 52px rgba(0,0,0,0.85)",
    bracketColor: "border-danger",
    shimmerColor: "rgba(196,34,12,0.08)",
    iconBorder: "border-danger/40", iconBg: "bg-danger/20",
    iconGlow: "0 0 18px rgba(196,34,12,0.6), inset 0 1px 0 rgba(255,255,255,0.08)",
    IconComp: AlertTriangle, iconColor: "text-danger",
    LabelIconComp: AlertTriangle, label: "PIÈGE !", labelColor: "text-danger",
    confetti: CONFETTI_TRAP,
  },
};

export function ItemToast({
  itemName,
  flags,
  onDone,
}: {
  itemName: string;
  flags: number;
  onDone: () => void;
}) {
  const theme = TOAST_THEMES[getToastVariant(flags)];
  const { IconComp, LabelIconComp } = theme;

  return (
    <div
      aria-atomic="true"
      aria-live="polite"
      className="pointer-events-none fixed left-1/2 top-6 z-50"
      onAnimationEnd={(e) => {
        if (e.animationName === "item-toast-slide") onDone();
      }}
      style={{ animation: "item-toast-slide 3s cubic-bezier(0.16, 1, 0.3, 1) both" }}
    >
      <div className="relative">
        <div
          className={`relative overflow-hidden rounded border ${theme.cardBorder} ${theme.cardBg} px-5 py-3.5`}
          style={{ boxShadow: theme.glow, minWidth: "260px", maxWidth: "380px" }}
        >
          <div aria-hidden="true" className={`pointer-events-none absolute left-0 top-0 h-3 w-3 border-l-2 border-t-2 ${theme.bracketColor}`} />
          <div aria-hidden="true" className={`pointer-events-none absolute right-0 top-0 h-3 w-3 border-r-2 border-t-2 ${theme.bracketColor}`} />
          <div aria-hidden="true" className={`pointer-events-none absolute bottom-0 left-0 h-3 w-3 border-b-2 border-l-2 ${theme.bracketColor}`} />
          <div aria-hidden="true" className={`pointer-events-none absolute bottom-0 right-0 h-3 w-3 border-b-2 border-r-2 ${theme.bracketColor}`} />
          <div
            aria-hidden="true"
            className="pointer-events-none absolute inset-0"
            style={{
              backgroundImage:
                "repeating-linear-gradient(0deg, transparent, transparent 2px, rgba(255,255,255,0.018) 2px, rgba(255,255,255,0.018) 3px)",
            }}
          />
          <div
            aria-hidden="true"
            className="pointer-events-none absolute inset-0 w-1/2"
            style={{
              background: `linear-gradient(to right, transparent, ${theme.shimmerColor}, transparent)`,
              animation: "item-toast-shimmer 0.9s ease-out 0.2s both",
            }}
          />
          <div className="relative flex items-center gap-3">
            <div
              className={`flex size-10 shrink-0 items-center justify-center rounded border ${theme.iconBorder} ${theme.iconBg}`}
              style={{ boxShadow: theme.iconGlow }}
            >
              <IconComp aria-hidden="true" className={`size-5 ${theme.iconColor}`} />
            </div>
            <div className="min-w-0 flex-1">
              <p className={`flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-[0.22em] ${theme.labelColor}`}>
                <LabelIconComp aria-hidden="true" className="size-3 shrink-0" />
                {theme.label}
              </p>
              <p
                className="mt-0.5 truncate font-heading text-sm font-bold leading-snug text-foreground"
                title={itemName}
              >
                {itemName}
              </p>
            </div>
          </div>
        </div>
        <div aria-hidden="true" className="pointer-events-none absolute inset-0">
          {theme.confetti.map((p, i) => (
            <div
              key={i}
              className="absolute"
              style={
                {
                  left: p.x, top: p.y, width: p.w, height: p.h,
                  backgroundColor: p.color,
                  borderRadius: p.round ? "50%" : "2px",
                  "--cx": p.cx, "--cy": p.cy, "--cr": p.cr,
                  animation: `confetti-burst ${p.dur}s ease-out ${p.delay}s both`,
                } as CSSProperties
              }
            />
          ))}
        </div>
      </div>
    </div>
  );
}
