"use client";

import { useQuery } from "@tanstack/react-query";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { PelleAmount } from "./pelle-amount";
import { fetchPelleCirculation, pelleReasonLabel, type PelleCirculation } from "./wallet-api";

const weekFormatter = new Intl.DateTimeFormat("fr-FR", { day: "numeric", month: "short" });

/**
 * The circulation of gold pelles (story 41.1 AC8): what exists, what was created and destroyed, week by
 * week and per reason - enough to see an inflation coming before it hurts the shop.
 */
export function AdminPellesDashboard() {
  const { data, isLoading } = useQuery({
    queryKey: ["admin-pelle-circulation"],
    queryFn: fetchPelleCirculation,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  return (
    <section className="grid gap-6 p-6 md:p-8">
      <header>
        <h1 className="font-heading text-2xl font-bold text-foreground">Pelles</h1>
        <p className="mt-1 text-sm text-muted-foreground">Circulation des pelles d&apos;or. Les pelles d&apos;événement n&apos;y figurent pas.</p>
      </header>
      {isLoading ? <p className="text-sm text-muted-foreground">Chargement…</p> : null}
      {!isLoading && !data ? <p className="text-sm text-danger">Impossible de charger la circulation des pelles.</p> : null}
      {data ? <CirculationView circulation={data} /> : null}
    </section>
  );
}

export function CirculationView({ circulation }: { circulation: PelleCirculation }) {
  const peak = Math.max(1, ...circulation.weeks.map((week) => Math.max(week.created, week.destroyed)));

  return (
    <div className="grid gap-6">
      <dl className="grid gap-4 sm:grid-cols-3">
        <Stat label="En circulation">
          <PelleAmount amount={circulation.goldInCirculation} className="text-warning" />
        </Stat>
        <Stat label="Créées">
          <PelleAmount amount={circulation.created} className="text-success" />
        </Stat>
        <Stat label="Détruites">
          <PelleAmount amount={circulation.destroyed} className="text-danger" />
        </Stat>
      </dl>

      <section aria-labelledby="pelles-weeks" className="grid gap-3 rounded-xl border border-border p-5">
        <h2 className="font-heading text-lg font-semibold text-foreground" id="pelles-weeks">
          Par semaine
        </h2>
        <ul className="grid gap-2">
          {circulation.weeks.map((week) => (
            <li className="grid grid-cols-[5rem_minmax(0,1fr)] items-center gap-3 text-xs" key={week.weekStart}>
              <span className="text-muted-foreground">{weekFormatter.format(new Date(`${week.weekStart}T00:00:00Z`))}</span>
              <span className="grid gap-1">
                <Bar label={`${week.created} créées`} tone="bg-success" width={(week.created / peak) * 100} />
                <Bar label={`${week.destroyed} détruites`} tone="bg-danger" width={(week.destroyed / peak) * 100} />
              </span>
            </li>
          ))}
        </ul>
      </section>

      <section aria-labelledby="pelles-reasons" className="grid gap-3 rounded-xl border border-border p-5">
        <h2 className="font-heading text-lg font-semibold text-foreground" id="pelles-reasons">
          Par motif
        </h2>
        {circulation.byReason.length === 0 ? (
          <p className="text-sm text-muted-foreground">Aucun mouvement pour le moment.</p>
        ) : (
          <table className="w-full text-left text-sm">
            <thead className="text-xs text-muted-foreground">
              <tr>
                <th className="py-1 font-medium">Motif</th>
                <th className="py-1 text-right font-medium">Créées</th>
                <th className="py-1 text-right font-medium">Détruites</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {circulation.byReason.map((row) => (
                <tr key={row.reason}>
                  <td className="py-2">{pelleReasonLabel(row.reason)}</td>
                  <td className="py-2 text-right tabular-nums">{row.created}</td>
                  <td className="py-2 text-right tabular-nums">{row.destroyed}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>
    </div>
  );
}

function Stat({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="rounded-xl border border-border p-4">
      <dt className="text-sm text-muted-foreground">{label}</dt>
      <dd className="mt-1 font-heading text-2xl font-bold">{children}</dd>
    </div>
  );
}

function Bar({ width, tone, label }: { width: number; tone: string; label: string }) {
  return (
    <span className="flex items-center gap-2">
      <span aria-hidden className={`h-2 rounded-full ${tone}`} style={{ width: `${Math.max(width, 0.5)}%` }} />
      <span className="shrink-0 text-muted-foreground">{label}</span>
    </span>
  );
}
