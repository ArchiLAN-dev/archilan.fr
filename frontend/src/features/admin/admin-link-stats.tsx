"use client";

import { useQuery } from "@tanstack/react-query";
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";

import { fetchLinkStats, type LinkStats, type TrackedLink } from "@/features/links/links-api";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";

/** Story 55.2: what the counts are, said wherever they are shown. */
export const VISITS_NOTE =
  "Des passages, pas des visiteurs uniques : sans cookie ni IP, deux clics de la même personne comptent deux fois. Les aperçus Discord et les robots ne comptent pas.";

const dayFormatter = new Intl.DateTimeFormat("fr-FR", { day: "numeric", month: "short", timeZone: "UTC" });

/** « 17 nov. » for `2026-11-17` (a day in Paris, read as a plain date). */
export function dayLabel(day: string): string {
  return dayFormatter.format(new Date(`${day}T00:00:00Z`));
}

/** An empty host: the browser did not say where the visitor came from (Discord's app, a QR code, a typed address...). */
export function originLabel(host: string): string {
  return host === "" ? "Direct ou inconnu" : host;
}

export function linkStatsKey(id: string) {
  return ["admin-link-stats", id] as const;
}

const axisTick = { fill: "var(--color-text-muted)", fontSize: 12 };

export function AdminLinkStats({ link }: { link: TrackedLink }) {
  const { data, isLoading } = useQuery({ queryKey: linkStatsKey(link.id), queryFn: () => fetchLinkStats(link.id), staleTime: DEFAULT_STALE_TIME, retry: false });

  return (
    <div className="grid gap-5">
      <dl className="grid grid-cols-3 gap-3">
        {(
          [
            ["7 derniers jours", link.visits.last7],
            ["30 derniers jours", link.visits.last30],
            ["Depuis la création", link.visits.total],
          ] as const
        ).map(([label, value]) => (
          <div className="rounded-xl border border-border bg-surface p-3" key={label}>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="font-heading text-2xl font-bold text-foreground">{value}</dd>
          </div>
        ))}
      </dl>
      {isLoading ? <p className="text-sm text-muted-foreground">Chargement…</p> : null}
      {!isLoading && data === null ? <p className="text-sm text-danger">Impossible de charger les statistiques.</p> : null}
      {data !== undefined && data !== null ? <LinkStatsView stats={data} /> : null}
      <p className="text-xs text-muted-foreground">{VISITS_NOTE}</p>
    </div>
  );
}

export function LinkStatsView({ stats }: { stats: LinkStats }) {
  const rows = stats.days.map((day) => ({ label: dayLabel(day.day), visits: day.visits }));
  const total = stats.origins.reduce((sum, origin) => sum + origin.visits, 0);

  return (
    <>
      <section className="grid gap-2">
        <h3 className="text-sm font-semibold text-foreground">Passages par jour, sur 90 jours</h3>
        <div aria-hidden className="h-52">
          <ResponsiveContainer height="100%" width="100%">
            <BarChart data={rows} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
              <CartesianGrid stroke="var(--color-border)" strokeOpacity={0.5} vertical={false} />
              <XAxis axisLine={{ stroke: "var(--color-border)" }} dataKey="label" interval="preserveStartEnd" minTickGap={24} tick={axisTick} tickLine={false} />
              <YAxis allowDecimals={false} axisLine={false} tick={axisTick} tickLine={false} width={32} />
              <Tooltip
                contentStyle={{ background: "var(--color-surface)", border: "1px solid var(--color-border)", borderRadius: 8, color: "var(--color-text)", fontSize: 12 }}
                cursor={{ fill: "var(--color-border)", fillOpacity: 0.3 }}
                labelStyle={{ color: "var(--color-text-muted)" }}
              />
              <Bar dataKey="visits" fill="var(--color-accent-text)" maxBarSize={10} name="Passages" radius={[3, 3, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
        </div>
        <table className="sr-only">
          <caption>Passages par jour</caption>
          <tbody>
            {stats.days.map((day) => (
              <tr key={day.day}>
                <th scope="row">{dayLabel(day.day)}</th>
                <td>{day.visits}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>
      <section className="grid gap-2">
        <h3 className="text-sm font-semibold text-foreground">D&apos;où viennent les passages (90 jours)</h3>
        {stats.origins.length === 0 ? (
          <p className="text-sm text-muted-foreground">Aucun passage sur la période.</p>
        ) : (
          <ul className="grid gap-1.5">
            {stats.origins.map((origin) => (
              <li className="grid grid-cols-[1fr_auto] items-center gap-3 text-sm" key={origin.host}>
                <span className="truncate text-foreground">{originLabel(origin.host)}</span>
                <span className="tabular-nums text-muted-foreground">
                  {origin.visits} ({total === 0 ? 0 : Math.round((origin.visits / total) * 100)} %)
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>
    </>
  );
}
