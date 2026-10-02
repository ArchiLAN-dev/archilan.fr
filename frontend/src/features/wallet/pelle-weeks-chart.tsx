"use client";

import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";

import type { PelleCirculation } from "./wallet-api";

const weekFormatter = new Intl.DateTimeFormat("fr-FR", { day: "numeric", month: "short", timeZone: "UTC" });

export function weekLabel(weekStart: string): string {
  return weekFormatter.format(new Date(`${weekStart}T00:00:00Z`));
}

/**
 * Gold pelles created and destroyed, week by week (story 41.1 AC8): two bars per week on one axis, so a
 * week where far more pelles appear than leave stands out. The hidden table carries the same numbers for
 * screen readers.
 */
export function PelleWeeksChart({ weeks }: { weeks: PelleCirculation["weeks"] }) {
  const rows = weeks.map((week) => ({ ...week, label: weekLabel(week.weekStart) }));

  return (
    <>
      <div aria-hidden className="h-56">
        <ResponsiveContainer height="100%" width="100%">
          <BarChart barGap={2} data={rows} margin={{ top: 8, right: 8, bottom: 0, left: -16 }}>
            <CartesianGrid stroke="var(--color-border)" strokeOpacity={0.5} vertical={false} />
            <XAxis
              axisLine={{ stroke: "var(--color-border)" }}
              dataKey="label"
              interval="preserveStartEnd"
              tick={{ fill: "var(--color-text-muted)", fontSize: 12 }}
              tickLine={false}
            />
            <YAxis allowDecimals={false} axisLine={false} tick={{ fill: "var(--color-text-muted)", fontSize: 12 }} tickLine={false} />
            <Tooltip
              contentStyle={{
                background: "var(--color-surface)",
                border: "1px solid var(--color-border)",
                borderRadius: 8,
                color: "var(--color-text)",
                fontSize: 12,
              }}
              cursor={{ fill: "var(--color-border)", fillOpacity: 0.3 }}
              labelFormatter={(label) => `Semaine du ${String(label)}`}
              labelStyle={{ color: "var(--color-text-muted)" }}
            />
            <Legend iconType="circle" wrapperStyle={{ color: "var(--color-text-muted)", fontSize: 12 }} />
            <Bar dataKey="created" fill="var(--color-success)" maxBarSize={14} name="Créées" radius={[4, 4, 0, 0]} />
            <Bar dataKey="destroyed" fill="var(--color-danger)" maxBarSize={14} name="Détruites" radius={[4, 4, 0, 0]} />
          </BarChart>
        </ResponsiveContainer>
      </div>
      <table className="sr-only">
        <caption>Pelles d&apos;or créées et détruites par semaine</caption>
        <thead>
          <tr>
            <th scope="col">Semaine du</th>
            <th scope="col">Créées</th>
            <th scope="col">Détruites</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.weekStart}>
              <td>{row.label}</td>
              <td>{row.created}</td>
              <td>{row.destroyed}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </>
  );
}
