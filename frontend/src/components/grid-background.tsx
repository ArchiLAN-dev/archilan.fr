"use client";

import { useEffect, useRef } from "react";

const GRID = 40;
const RADIUS = 160;
const RISE = 0.16;
const DECAY = 0.045;

function hexToRgb(varName: string, fallback: string): string {
  const raw = getComputedStyle(document.documentElement).getPropertyValue(varName).trim();
  const hex = raw.match(/^#([0-9a-f]{6})$/i)?.[1];
  if (!hex) return fallback;
  const r = Number.parseInt(hex.slice(0, 2), 16);
  const g = Number.parseInt(hex.slice(2, 4), 16);
  const b = Number.parseInt(hex.slice(4, 6), 16);
  return `${r},${g},${b}`;
}

export function GridBackground() {
  const ref = useRef<HTMLCanvasElement>(null);

  useEffect(() => {
    const canvas = ref.current;
    if (!canvas) return;
    const ctx = canvas.getContext("2d");
    if (!ctx) return;

    let w = 0, h = 0, cols = 0, rows = 0, ox = 0;
    let heat: Float32Array = new Float32Array(0);
    let mx = -9999, my = -9999;
    let raf = 0;
    const accent = hexToRgb("--color-accent", "255,255,255");
    const border = hexToRgb("--color-border", "40,31,82");

    function resize() {
      w = window.innerWidth;
      h = window.innerHeight;
      canvas!.width = w;
      canvas!.height = h;
      // Match CSS background-position: left top with background-size: 40px 40px.
      // The CSS places a dot at the center of the first tile: x = GRID/2, y = GRID/2.
      ox = GRID / 2;
      cols = Math.floor(w / GRID) + 3;
      rows = Math.floor(h / GRID) + 2;
      heat = new Float32Array(cols * rows);
    }

    function frame() {
      ctx!.clearRect(0, 0, w, h);

      for (let r = 0; r < rows; r++) {
        for (let c = 0; c < cols; c++) {
          // ox - GRID shifts back one column so dots to the left of ox are drawn.
          const x = ox - GRID + c * GRID;
          const y = GRID / 2 + r * GRID;
          const i = r * cols + c;

          const dx = x - mx;
          const dy = y - my;
          const dist = Math.sqrt(dx * dx + dy * dy);
          const target = dist < RADIUS ? (1 - dist / RADIUS) ** 1.8 : 0;
          const delta = target - heat[i];
          heat[i] = Math.max(0, heat[i] + delta * (delta > 0 ? RISE : DECAY));

          const v = heat[i];

          if (v < 0.01) {
            ctx!.beginPath();
            ctx!.arc(x, y, 1.5, 0, Math.PI * 2);
            ctx!.fillStyle = `rgba(${border},0.8)`;
            ctx!.fill();
          } else {
            // outer glow halo
            const glowR = 6 + 16 * v;
            const glow = ctx!.createRadialGradient(x, y, 0, x, y, glowR);
            glow.addColorStop(0, `rgba(${accent},${0.65 * v})`);
            glow.addColorStop(0.4, `rgba(${accent},${0.18 * v})`);
            glow.addColorStop(1, `rgba(${accent},0)`);
            ctx!.beginPath();
            ctx!.arc(x, y, glowR, 0, Math.PI * 2);
            ctx!.fillStyle = glow;
            ctx!.fill();

            // solid core dot
            ctx!.beginPath();
            ctx!.arc(x, y, 1.2 + 2.2 * v, 0, Math.PI * 2);
            ctx!.fillStyle = `rgba(${accent},${0.55 + 0.45 * v})`;
            ctx!.fill();
          }
        }
      }

      raf = requestAnimationFrame(frame);
    }

    const onMove = (e: MouseEvent) => { mx = e.clientX; my = e.clientY; };
    const onLeave = () => { mx = -9999; my = -9999; };

    resize();
    window.addEventListener("resize", resize);
    window.addEventListener("mousemove", onMove);
    document.addEventListener("mouseleave", onLeave);
    raf = requestAnimationFrame(frame);

    return () => {
      cancelAnimationFrame(raf);
      window.removeEventListener("resize", resize);
      window.removeEventListener("mousemove", onMove);
      document.removeEventListener("mouseleave", onLeave);
    };
  }, []);

  return (
    <canvas
      ref={ref}
      aria-hidden="true"
      className="pointer-events-none fixed inset-0 z-[-1]"
    />
  );
}
