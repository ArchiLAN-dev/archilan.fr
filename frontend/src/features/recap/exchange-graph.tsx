"use client";

import { useEffect, useRef, useState } from "react";

export type GraphNode = {
  slotId: string;
  label: string;
  game: string;
  sent: number;
  received: number;
};

export type GraphEdge = {
  fromSlotId: string;
  toSlotId: string;
  count: number;
};

type Props = {
  nodes: GraphNode[];
  edges: GraphEdge[];
};

type Body = {
  node: GraphNode;
  x: number;
  y: number;
  vx: number;
  vy: number;
  r: number;
  colorIndex: number;
};

const CHART_COLORS = 5;
const NODE_BASE_RADIUS = 16;
const NODE_MAX_EXTRA_RADIUS = 18;
const EDGE_MIN_WIDTH = 1;
const EDGE_MAX_WIDTH = 6;

function readTokens(el: HTMLElement) {
  const style = getComputedStyle(el);
  const token = (name: string, fallback: string) => style.getPropertyValue(name).trim() || fallback;
  return {
    foreground: token("--color-foreground", "#e5e7eb"),
    muted: token("--color-muted-foreground", "#9ca3af"),
    border: token("--color-border", "#374151"),
    accent: token("--color-accent", "#a855f7"),
    surface: token("--color-card", "#111827"),
    charts: Array.from({ length: CHART_COLORS }, (_, i) => token(`--color-chart-${i + 1}`, "#a855f7")),
  };
}

/**
 * Interactive, client-only force-directed rendering of the item-exchange graph.
 * Hand-rolled on <canvas> (no graph dependency) following the project's canvas
 * lifecycle: DPR-aware resize, RAF loop, ResizeObserver, full cleanup. Layout is
 * deterministic (circle seed, no randomness) so it never differs between renders,
 * and it settles instantly when the viewer prefers reduced motion. The exchange
 * list is mirrored in a visually-hidden table for accessibility and crawlers.
 */
export function ExchangeGraph({ nodes, edges }: Props) {
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const [hovered, setHovered] = useState<GraphNode | null>(null);
  const [tooltip, setTooltip] = useState<{ x: number; y: number } | null>(null);

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas || nodes.length === 0) return;
    const ctx = canvas.getContext("2d");
    if (!ctx) return;

    const tokens = readTokens(canvas);
    const reduceMotion =
      typeof matchMedia === "function" && matchMedia("(prefers-reduced-motion: reduce)").matches;

    const maxThroughput = Math.max(1, ...nodes.map((n) => n.sent + n.received));
    const maxCount = Math.max(1, ...edges.map((e) => e.count));

    let width = canvas.clientWidth;
    let height = canvas.clientHeight;

    // Deterministic circle seed - no Math.random during setup (AC-HK3).
    const bodies: Body[] = nodes.map((node, i) => {
      const angle = (2 * Math.PI * i) / nodes.length;
      const radius = Math.min(width, height) * 0.32;
      const throughput = node.sent + node.received;
      return {
        node,
        x: width / 2 + radius * Math.cos(angle),
        y: height / 2 + radius * Math.sin(angle),
        vx: 0,
        vy: 0,
        r: NODE_BASE_RADIUS + NODE_MAX_EXTRA_RADIUS * Math.sqrt(throughput / maxThroughput),
        colorIndex: i % CHART_COLORS,
      };
    });
    const byId = new Map(bodies.map((b) => [b.node.slotId, b]));

    function step() {
      const center = { x: width / 2, y: height / 2 };
      for (const b of bodies) {
        // Repulsion between every pair.
        for (const other of bodies) {
          if (other === b) continue;
          const dx = b.x - other.x;
          const dy = b.y - other.y;
          const dist2 = dx * dx + dy * dy || 0.01;
          const force = 9000 / dist2;
          const dist = Math.sqrt(dist2);
          b.vx += (dx / dist) * force;
          b.vy += (dy / dist) * force;
        }
        // Centering pull keeps the graph on screen.
        b.vx += (center.x - b.x) * 0.01;
        b.vy += (center.y - b.y) * 0.01;
      }
      // Spring attraction along edges.
      for (const e of edges) {
        const a = byId.get(e.fromSlotId);
        const c = byId.get(e.toSlotId);
        if (!a || !c) continue;
        const dx = c.x - a.x;
        const dy = c.y - a.y;
        const dist = Math.sqrt(dx * dx + dy * dy) || 0.01;
        const pull = (dist - 150) * 0.008;
        const fx = (dx / dist) * pull;
        const fy = (dy / dist) * pull;
        a.vx += fx;
        a.vy += fy;
        c.vx -= fx;
        c.vy -= fy;
      }
      for (const b of bodies) {
        if (b === draggingRef) continue;
        b.vx *= 0.82;
        b.vy *= 0.82;
        b.x += b.vx;
        b.y += b.vy;
        b.x = Math.max(b.r, Math.min(width - b.r, b.x));
        b.y = Math.max(b.r, Math.min(height - b.r, b.y));
      }
    }

    function arrow(ctx2: CanvasRenderingContext2D, a: Body, c: Body, highlight: boolean, width2: number) {
      const dx = c.x - a.x;
      const dy = c.y - a.y;
      const dist = Math.sqrt(dx * dx + dy * dy) || 0.01;
      const ux = dx / dist;
      const uy = dy / dist;
      const sx = a.x + ux * a.r;
      const sy = a.y + uy * a.r;
      const ex = c.x - ux * (c.r + 6);
      const ey = c.y - uy * (c.r + 6);
      ctx2.strokeStyle = highlight ? tokens.accent : tokens.border;
      ctx2.globalAlpha = highlight ? 1 : hoveredRef ? 0.15 : 0.55;
      ctx2.lineWidth = width2;
      ctx2.beginPath();
      ctx2.moveTo(sx, sy);
      ctx2.lineTo(ex, ey);
      ctx2.stroke();
      // Arrowhead.
      const head = 6 + width2;
      ctx2.beginPath();
      ctx2.moveTo(ex, ey);
      ctx2.lineTo(ex - ux * head - uy * head * 0.5, ey - uy * head + ux * head * 0.5);
      ctx2.lineTo(ex - ux * head + uy * head * 0.5, ey - uy * head - ux * head * 0.5);
      ctx2.closePath();
      ctx2.fillStyle = highlight ? tokens.accent : tokens.border;
      ctx2.fill();
      ctx2.globalAlpha = 1;
    }

    function draw() {
      if (!ctx) return;
      ctx.clearRect(0, 0, width, height);
      const hoveredId = hoveredRef?.slotId ?? null;

      for (const e of edges) {
        const a = byId.get(e.fromSlotId);
        const c = byId.get(e.toSlotId);
        if (!a || !c) continue;
        const highlight = hoveredId !== null && (e.fromSlotId === hoveredId || e.toSlotId === hoveredId);
        const w = EDGE_MIN_WIDTH + (EDGE_MAX_WIDTH - EDGE_MIN_WIDTH) * (e.count / maxCount);
        arrow(ctx, a, c, highlight, w);
      }

      for (const b of bodies) {
        const dim = hoveredRef !== null && hoveredRef.slotId !== b.node.slotId;
        ctx.globalAlpha = dim ? 0.35 : 1;
        ctx.beginPath();
        ctx.arc(b.x, b.y, b.r, 0, 2 * Math.PI);
        ctx.fillStyle = tokens.charts[b.colorIndex];
        ctx.fill();
        ctx.lineWidth = 2;
        ctx.strokeStyle = tokens.surface;
        ctx.stroke();

        ctx.globalAlpha = dim ? 0.5 : 1;
        ctx.fillStyle = tokens.foreground;
        ctx.font = "600 12px system-ui, sans-serif";
        ctx.textAlign = "center";
        ctx.textBaseline = "top";
        ctx.fillText(b.node.label, b.x, b.y + b.r + 4);
        ctx.globalAlpha = 1;
      }
    }

    let raf = 0;
    let cooldown = reduceMotion ? 0 : 260;

    // Ref-like closures over hover/drag state so the RAF loop sees the latest
    // values without the effect (and the whole simulation) restarting.
    let hoveredRef: GraphNode | null = null;
    let draggingRef: Body | null = null;

    function frame() {
      if (cooldown > 0 || draggingRef) {
        step();
        cooldown = Math.max(0, cooldown - 1);
      }
      draw();
      raf = requestAnimationFrame(frame);
    }

    function resize() {
      if (!canvas || !ctx) return;
      width = canvas.clientWidth;
      height = canvas.clientHeight;
      const dpr = Math.min(devicePixelRatio, 2);
      canvas.width = Math.round(width * dpr);
      canvas.height = Math.round(height * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      cooldown = Math.max(cooldown, reduceMotion ? 0 : 60);
    }

    function bodyAt(px: number, py: number): Body | null {
      for (const b of bodies) {
        const dx = px - b.x;
        const dy = py - b.y;
        if (dx * dx + dy * dy <= b.r * b.r) return b;
      }
      return null;
    }

    function toLocal(evt: PointerEvent) {
      const rect = canvas!.getBoundingClientRect();
      return { x: evt.clientX - rect.left, y: evt.clientY - rect.top };
    }

    function onMove(evt: PointerEvent) {
      const p = toLocal(evt);
      if (draggingRef) {
        draggingRef.x = p.x;
        draggingRef.y = p.y;
        draggingRef.vx = 0;
        draggingRef.vy = 0;
        return;
      }
      const hit = bodyAt(p.x, p.y);
      hoveredRef = hit?.node ?? null;
      setHovered(hit?.node ?? null);
      setTooltip(hit ? { x: p.x, y: p.y } : null);
      canvas!.style.cursor = hit ? "grab" : "default";
    }

    function onDown(evt: PointerEvent) {
      const p = toLocal(evt);
      const hit = bodyAt(p.x, p.y);
      if (hit) {
        draggingRef = hit;
        canvas!.setPointerCapture(evt.pointerId);
        canvas!.style.cursor = "grabbing";
      }
    }

    function onUp(evt: PointerEvent) {
      draggingRef = null;
      canvas!.style.cursor = "grab";
      if (canvas!.hasPointerCapture(evt.pointerId)) canvas!.releasePointerCapture(evt.pointerId);
    }

    function onLeave() {
      hoveredRef = null;
      setHovered(null);
      setTooltip(null);
    }

    resize();
    const ro = new ResizeObserver(resize);
    ro.observe(canvas);
    canvas.addEventListener("pointermove", onMove);
    canvas.addEventListener("pointerdown", onDown);
    canvas.addEventListener("pointerup", onUp);
    canvas.addEventListener("pointerleave", onLeave);
    raf = requestAnimationFrame(frame);

    return () => {
      cancelAnimationFrame(raf);
      ro.disconnect();
      canvas.removeEventListener("pointermove", onMove);
      canvas.removeEventListener("pointerdown", onDown);
      canvas.removeEventListener("pointerup", onUp);
      canvas.removeEventListener("pointerleave", onLeave);
    };
  }, [nodes, edges]);

  return (
    <div className="relative">
      <canvas
        aria-hidden="true"
        className="h-[26rem] w-full touch-none rounded-lg border border-border bg-surface"
        ref={canvasRef}
      />
      {hovered && tooltip ? (
        <div
          className="pointer-events-none absolute z-10 max-w-56 -translate-x-1/2 -translate-y-full rounded-md border border-border bg-surface px-3 py-2 text-xs shadow-lg"
          style={{ left: tooltip.x, top: tooltip.y - 12 }}
        >
          <p className="font-semibold text-foreground">{hovered.label}</p>
          <p className="text-muted-foreground">{hovered.game}</p>
          <p className="mt-1 text-muted-foreground">
            {hovered.sent} envoyé{hovered.sent > 1 ? "s" : ""} - {hovered.received} reçu
            {hovered.received > 1 ? "s" : ""}
          </p>
        </div>
      ) : null}

      {/* Accessible / crawlable mirror of the exchange graph. */}
      <table className="sr-only">
        <caption>Graphe des échanges d&apos;objets entre joueurs</caption>
        <thead>
          <tr>
            <th>De</th>
            <th>Vers</th>
            <th>Objets envoyés</th>
          </tr>
        </thead>
        <tbody>
          {edges.map((e) => (
            <tr key={`${e.fromSlotId}-${e.toSlotId}`}>
              <td>{nodes.find((n) => n.slotId === e.fromSlotId)?.label ?? e.fromSlotId}</td>
              <td>{nodes.find((n) => n.slotId === e.toSlotId)?.label ?? e.toSlotId}</td>
              <td>{e.count}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
