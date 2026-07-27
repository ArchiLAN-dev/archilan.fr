"use client";

import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import type { ChecksPlayer, ChecksRow } from "./build-checks-series";

/**
 * Checks over time, one line per player. Colours come from the player (by slot), so hiding a player
 * never repaints the others. The X axis is real time-of-day (tight to the data - no empty run-up), with
 * the date shown when the run spans more than one day. Dark-surface tooltip/grid via the app tokens.
 */
export function ChecksChart({ players, rows }: { players: ChecksPlayer[]; rows: ChecksRow[] }) {
  if (players.length === 0 || rows.length === 0) {
    return null;
  }

  // Show the date on the ticks only when the run actually crosses a day boundary.
  const multiDay = new Date(rows[0].t).toDateString() !== new Date(rows[rows.length - 1].t).toDateString();
  const tick = (t: number) => (multiDay ? `${dm(t)} ${hm(t)}` : hm(t));
  const precise = (t: number) => (multiDay ? `${dm(t)} ${hms(t)}` : hms(t));

  return (
    <div className="h-72 w-full">
      <ResponsiveContainer height="100%" width="100%">
        <LineChart data={rows} margin={{ top: 8, right: 16, bottom: 4, left: -8 }}>
          <CartesianGrid stroke="var(--color-border)" strokeOpacity={0.5} vertical={false} />
          <XAxis
            axisLine={{ stroke: "var(--color-border)" }}
            dataKey="t"
            domain={["dataMin", "dataMax"]}
            scale="time"
            tick={{ fill: "var(--color-text-muted)", fontSize: 12 }}
            tickFormatter={tick}
            tickLine={false}
            type="number"
          />
          <YAxis
            allowDecimals={false}
            axisLine={false}
            tick={{ fill: "var(--color-text-muted)", fontSize: 12 }}
            tickLine={false}
            width={36}
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
              dot={false}
              key={player.key}
              name={player.name}
              stroke={player.color}
              strokeWidth={2}
              type="monotone"
            />
          ))}
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
