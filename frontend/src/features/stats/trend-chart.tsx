"use client";

import { Bar, BarChart, CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";

import type { StatsBucket } from "./stats-api";

const weekFormatter = new Intl.DateTimeFormat("fr-FR", { day: "numeric", month: "short", timeZone: "UTC" });
const monthFormatter = new Intl.DateTimeFormat("fr-FR", { month: "short", year: "numeric", timeZone: "UTC" });

/** « 28 sept. » for a week, « oct. 2026 » for a month. */
export function bucketLabel(start: string, granularity: "week" | "month"): string {
  const date = new Date(`${start}T00:00:00Z`);
  return granularity === "week" ? weekFormatter.format(date) : monthFormatter.format(date);
}

export type ChartSeries = { key: string; label: string; color: string; buckets: StatsBucket[] };

type Props = {
  kind: "bar" | "line";
  granularity: "week" | "month";
  series: ChartSeries[];
  /** Names the hidden table for screen readers. */
  caption: string;
};

const axisTick = { fill: "var(--color-text-muted)", fontSize: 12 };

/**
 * A time chart of the statistics page (story 42.1): one vertical axis, a tooltip on hover, a legend from two
 * series on, and the same numbers in a table hidden for screen readers. The bucket in progress is named as
 * such in the tooltip, so a low last bar is not read as a drop.
 */
export function TrendChart({ kind, granularity, series, caption }: Props) {
  const first = series[0];
  if (first === undefined) return null;

  const rows = first.buckets.map((bucket, index) => {
    const row: Record<string, string | number> = {
      label: bucketLabel(bucket.start, granularity) + (bucket.current ? " (en cours)" : ""),
      start: bucket.start,
    };
    for (const s of series) row[s.key] = s.buckets[index]?.value ?? 0;
    return row;
  });

  const tooltip = (
    <Tooltip
      contentStyle={{
        background: "var(--color-surface)",
        border: "1px solid var(--color-border)",
        borderRadius: 8,
        color: "var(--color-text)",
        fontSize: 12,
      }}
      cursor={kind === "bar" ? { fill: "var(--color-border)", fillOpacity: 0.3 } : { stroke: "var(--color-border)" }}
      labelStyle={{ color: "var(--color-text-muted)" }}
    />
  );
  const legend = series.length > 1 ? <Legend iconType="circle" wrapperStyle={{ color: "var(--color-text-muted)", fontSize: 12 }} /> : null;
  const xAxis = (
    <XAxis
      axisLine={{ stroke: "var(--color-border)" }}
      dataKey="label"
      interval="preserveStartEnd"
      minTickGap={16}
      tick={axisTick}
      tickFormatter={(label: string) => label.replace(" (en cours)", "")}
      tickLine={false}
    />
  );
  const yAxis = <YAxis allowDecimals={false} axisLine={false} tick={axisTick} tickLine={false} width={40} />;
  const grid = <CartesianGrid stroke="var(--color-border)" strokeOpacity={0.5} vertical={false} />;

  return (
    <>
      <div aria-hidden className="h-52">
        <ResponsiveContainer height="100%" width="100%">
          {kind === "bar" ? (
            <BarChart barGap={2} data={rows} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
              {grid}
              {xAxis}
              {yAxis}
              {tooltip}
              {legend}
              {series.map((s) => (
                <Bar dataKey={s.key} fill={s.color} key={s.key} maxBarSize={14} name={s.label} radius={[4, 4, 0, 0]} />
              ))}
            </BarChart>
          ) : (
            <LineChart data={rows} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
              {grid}
              {xAxis}
              {yAxis}
              {tooltip}
              {legend}
              {series.map((s) => (
                <Line
                  activeDot={{ r: 5 }}
                  dataKey={s.key}
                  dot={{ r: 3 }}
                  key={s.key}
                  name={s.label}
                  stroke={s.color}
                  strokeWidth={2}
                  type="monotone"
                />
              ))}
            </LineChart>
          )}
        </ResponsiveContainer>
      </div>
      <table className="sr-only">
        <caption>{caption}</caption>
        <thead>
          <tr>
            <th scope="col">{granularity === "week" ? "Semaine du" : "Mois"}</th>
            {series.map((s) => (
              <th key={s.key} scope="col">
                {s.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={String(row.start)}>
              <td>{row.label}</td>
              {series.map((s) => (
                <td key={s.key}>{row[s.key]}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </>
  );
}
