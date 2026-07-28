"use client";

import { useState, type Key } from "react";
import { CartesianGrid, Legend, Line, LineChart, ReferenceArea, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import type { ChecksPlayer, ChecksRow } from "./build-checks-series";

/** Recharts hands its mouse handlers the chart state; we only read the X value under the cursor. */
type ChartMouse = { activeLabel?: string | number };

type Zoom = [number, number] | null;

/** A player's goal-reached instant (story 32.9), already resolved to their series colour. */
export type ChartGoal = { key: string; name: string; color: string; at: number };

/** What recharts hands a Line's `dot` renderer; only the fields we read. */
type DotRenderProps = { key?: Key | null; cx?: number; cy?: number; payload?: ChecksRow };

/**
 * Marks the buckets where this player found at least one *progression* item (story 32.9) with a
 * filled dot on their line; other buckets get an invisible zero-radius dot (recharts requires an
 * element per point). Rows persisted before the flag existed count 0 and stay dot-less.
 */
function progressionDot(player: ChecksPlayer) {
  return function renderDot({ key, cx, cy, payload }: DotRenderProps) {
    const count = payload?.[player.progressionKey] ?? 0;
    if (cx === undefined || cy === undefined || count === 0) {
      return <circle cx={cx} cy={cy} fill="none" key={key} r={0} stroke="none" />;
    }
    return <circle cx={cx} cy={cy} fill={player.color} key={key} r={3.5} stroke="var(--color-surface)" strokeWidth={1} />;
  };
}

/**
 * Checks over time, one line per player. Colours come from the player (by slot), so hiding a player
 * never repaints the others. The X axis is real time-of-day (tight to the data - no empty run-up), with
 * the date shown when the run spans more than one day. Drag across the plot to select a range and zoom
 * into it; the committed zoom is owned by the parent (`zoom`/`onZoom`) so the reset control can sit with
 * the other filters. Dark-surface tooltip/grid via the app tokens.
 *
 * `goals` drop a dashed vertical line (in the player's colour, labelled with their name) at each
 * player's goal-reached instant; `ifOverflow="hidden"` keeps them honest under the zoom, and the
 * parent already filters them per day.
 */
export function ChecksChart({
  players,
  rows,
  zoom,
  onZoom,
  onHoverBucket,
  markerT,
  goals,
  measureLabel,
}: {
  players: ChecksPlayer[];
  rows: ChecksRow[];
  zoom: Zoom;
  onZoom: (zoom: Zoom) => void;
  onHoverBucket: (t: number | null) => void;
  markerT: number | null;
  goals: ChartGoal[];
  /** What the Y axis counts (story 32.10) - "Checks trouvés" or "Objets reçus". */
  measureLabel: string;
}) {
  // Transient drag selection (start/current X, in `t` epoch ms); the committed zoom lives in the parent.
  const [dragFrom, setDragFrom] = useState<number | null>(null);
  const [dragTo, setDragTo] = useState<number | null>(null);

  if (players.length === 0 || rows.length === 0) {
    return null;
  }

  const multiDay = new Date(rows[0].t).toDateString() !== new Date(rows[rows.length - 1].t).toDateString();
  const tick = (t: number) => (multiDay ? `${dm(t)} ${hm(t)}` : hm(t));
  const precise = (t: number) => (multiDay ? `${dm(t)} ${hms(t)}` : hms(t));

  function readX(state: ChartMouse): number | null {
    const t = Number(state.activeLabel);
    return Number.isNaN(t) ? null : t;
  }

  function onDown(state: ChartMouse): void {
    const t = readX(state);
    if (t !== null) {
      setDragFrom(t);
      setDragTo(t);
    }
  }

  function onMove(state: ChartMouse): void {
    const t = readX(state);
    onHoverBucket(t); // report the hovered bucket so the log can highlight its rows
    if (dragFrom === null) return;
    if (t !== null) setDragTo(t);
  }

  function onUp(): void {
    if (dragFrom !== null && dragTo !== null && dragFrom !== dragTo) {
      onZoom([Math.min(dragFrom, dragTo), Math.max(dragFrom, dragTo)]);
    }
    setDragFrom(null);
    setDragTo(null);
  }

  function onLeave(): void {
    onHoverBucket(null);
    setDragFrom(null);
    setDragTo(null);
  }

  const selecting = dragFrom !== null && dragTo !== null && dragFrom !== dragTo;

  return (
    <div className="h-72 w-full select-none">
      <ResponsiveContainer height="100%" width="100%">
        <LineChart data={rows} margin={{ top: 8, right: 16, bottom: 4, left: -8 }} onMouseDown={onDown} onMouseLeave={onLeave} onMouseMove={onMove} onMouseUp={onUp}>
          <CartesianGrid stroke="var(--color-border)" strokeOpacity={0.5} vertical={false} />
          <XAxis
            allowDataOverflow
            axisLine={{ stroke: "var(--color-border)" }}
            dataKey="t"
            domain={zoom ?? ["dataMin", "dataMax"]}
            scale="time"
            tick={{ fill: "var(--color-text-muted)", fontSize: 12 }}
            tickFormatter={tick}
            tickLine={false}
            type="number"
          />
          <YAxis
            allowDecimals={false}
            axisLine={false}
            label={{ value: measureLabel, angle: -90, position: "insideLeft", fill: "var(--color-text-muted)", fontSize: 11 }}
            tick={{ fill: "var(--color-text-muted)", fontSize: 12 }}
            tickLine={false}
            width={48}
          />
          <Tooltip
            contentStyle={{
              background: "var(--color-surface)",
              border: "1px solid var(--color-border)",
              borderRadius: 8,
              color: "var(--color-text)",
              fontSize: 13,
            }}
            labelFormatter={(t) => `À ${precise(Number(t))}`}
            labelStyle={{ color: "var(--color-text-muted)" }}
          />
          <Legend wrapperStyle={{ fontSize: 13 }} />
          {players.map((player) => (
            <Line
              dataKey={player.key}
              dot={progressionDot(player)}
              isAnimationActive={false}
              key={player.key}
              name={player.name}
              stroke={player.color}
              strokeWidth={2}
              type="monotone"
            />
          ))}
          {goals.map((goal) => (
            <ReferenceLine
              ifOverflow="hidden"
              key={`goal-${goal.key}`}
              label={{ value: goal.name, position: "insideTop", fill: goal.color, fontSize: 11 }}
              stroke={goal.color}
              strokeDasharray="6 4"
              strokeWidth={1.5}
              x={goal.at}
            />
          ))}
          {markerT !== null ? (
            <ReferenceLine stroke="var(--color-accent-text)" strokeDasharray="4 3" strokeWidth={1.5} x={markerT} />
          ) : null}
          {selecting ? (
            <ReferenceArea
              className="chart-select-pulse"
              fill="var(--color-accent-text)"
              fillOpacity={0.22}
              stroke="var(--color-accent-text)"
              strokeOpacity={0.9}
              strokeWidth={1.5}
              x1={Math.min(dragFrom, dragTo)}
              x2={Math.max(dragFrom, dragTo)}
            />
          ) : null}
        </LineChart>
      </ResponsiveContainer>
    </div>
  );
}

const two = (n: number): string => String(n).padStart(2, "0");

/** Local time of day of a bucket epoch (ms): "22h30". */
function hm(epochMs: number): string {
  const date = new Date(epochMs);
  return `${date.getHours()}h${two(date.getMinutes())}`;
}

/** With seconds, for the tooltip so sub-minute buckets stay distinct: "22h30:15". */
function hms(epochMs: number): string {
  return `${hm(epochMs)}:${two(new Date(epochMs).getSeconds())}`;
}

/** Day + month, shown when the run spans multiple days: "28/07". */
function dm(epochMs: number): string {
  const date = new Date(epochMs);
  return `${two(date.getDate())}/${two(date.getMonth() + 1)}`;
}
