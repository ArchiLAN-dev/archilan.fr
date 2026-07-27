"use client";

import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import type { ChecksPlayer, ChecksRow } from "./build-checks-series";

/**
 * Cumulative checks over time, one line per player. Colours come from the player (by slot), so hiding a
 * player never repaints the others. Dark-surface tooltip/grid via the app tokens.
 */
export function ChecksChart({ players, rows }: { players: ChecksPlayer[]; rows: ChecksRow[] }) {
  if (players.length === 0 || rows.length === 0) {
    return null;
  }

  return (
    <div className="h-72 w-full">
      <ResponsiveContainer height="100%" width="100%">
        <LineChart data={rows} margin={{ top: 8, right: 16, bottom: 4, left: -8 }}>
          <CartesianGrid stroke="var(--color-border)" strokeOpacity={0.5} vertical={false} />
          <XAxis
            axisLine={{ stroke: "var(--color-border)" }}
            dataKey="t"
            tick={{ fill: "var(--color-text-muted)", fontSize: 12 }}
            tickFormatter={clock}
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
            labelFormatter={(t) => `À ${clockPrecise(Number(t))}`}
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

/** A bucket's wall-clock epoch (ms) as local time of day, e.g. "22h30". */
function clock(epochMs: number): string {
  const date = new Date(epochMs);
  return `${date.getHours()}h${String(date.getMinutes()).padStart(2, "0")}`;
}

/** Same, with seconds - for the tooltip, so sub-minute buckets stay distinct: "22h30:15". */
function clockPrecise(epochMs: number): string {
  const date = new Date(epochMs);
  return `${clock(epochMs)}:${String(date.getSeconds()).padStart(2, "0")}`;
}
