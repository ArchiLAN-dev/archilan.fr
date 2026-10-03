"use client";

import type { ReactNode } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { Shovel, Users } from "lucide-react";
import type { LucideIcon } from "lucide-react";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { PelleAmount } from "@/features/wallet/pelle-amount";
import { pelleReasonLabel } from "@/features/wallet/wallet-api";
import { KeyFigure, formatCount } from "./key-figure";
import {
  DEFAULT_STATS_PERIOD,
  STATS_PERIODS,
  fetchCommunityStats,
  fetchPelleStats,
  parseStatsPeriod,
  type CommunityStats,
  type PelleStats,
  type StatsPeriodCode,
} from "./stats-api";
import { TrendChart } from "./trend-chart";

const SERIES_COLOR = "var(--color-accent-text)";

/**
 * The admin statistics page (story 42.1): one period for the whole page, kept in the address so a shared link
 * shows the same view, and one section per context that loads on its own.
 */
export function AdminStatsPage() {
  const searchParams = useSearchParams();
  const router = useRouter();
  const pathname = usePathname();
  const period = parseStatsPeriod(searchParams.get("periode"));

  function choose(code: StatsPeriodCode): void {
    router.replace(code === DEFAULT_STATS_PERIOD ? pathname : `${pathname}?periode=${code}`, { scroll: false });
  }

  return (
    <div className="grid gap-8 p-6 md:p-8">
      <header className="flex flex-wrap items-end justify-between gap-4">
        <div className="grid gap-1">
          <h1 className="font-heading text-2xl font-bold text-foreground">Statistiques</h1>
          <p className="text-sm text-muted-foreground">L&apos;activité du site sur la période, comparée à la période précédente.</p>
        </div>
        <PeriodPicker onChoose={choose} value={period} />
      </header>

      <CommunitySection period={period} />
      <PellesSection period={period} />
    </div>
  );
}

export function PeriodPicker({ value, onChoose }: { value: StatsPeriodCode; onChoose: (code: StatsPeriodCode) => void }) {
  return (
    <div aria-label="Période" className="inline-flex rounded-lg border border-border p-0.5" role="group">
      {STATS_PERIODS.map((period) => (
        <button
          aria-pressed={period.code === value}
          className={`min-h-9 rounded-md px-3 text-sm font-semibold transition-colors ${
            period.code === value ? "bg-accent text-foreground" : "text-muted-foreground hover:text-foreground"
          }`}
          key={period.code}
          onClick={() => onChoose(period.code)}
          type="button"
        >
          {period.label}
        </button>
      ))}
    </div>
  );
}

function Section({ id, title, icon: Icon, children }: { id: string; title: string; icon: LucideIcon; children: ReactNode }) {
  return (
    <section aria-labelledby={`${id}-title`} className="grid scroll-mt-6 gap-5 border-t border-border pt-6" id={id}>
      <h2 className="flex items-center gap-2 font-heading text-xl font-semibold text-foreground" id={`${id}-title`}>
        <Icon aria-hidden className="size-5 text-accent-text" />
        {title}
      </h2>
      {children}
    </section>
  );
}

function SectionState({ loading }: { loading: boolean }) {
  return loading ? (
    <div aria-hidden className="h-40 animate-pulse rounded-xl bg-surface-2" />
  ) : (
    <p className="text-sm text-danger">Impossible de charger cette section pour le moment.</p>
  );
}

function ChartBlock({ title, definition, children }: { title: string; definition: string; children: ReactNode }) {
  return (
    <figure className="grid gap-2 rounded-xl border border-border p-4">
      <figcaption className="grid gap-0.5">
        <span className="font-semibold text-foreground">{title}</span>
        <span className="text-xs text-muted-foreground">{definition}</span>
      </figcaption>
      {children}
    </figure>
  );
}

function CommunitySection({ period }: { period: StatsPeriodCode }) {
  const { data, isLoading } = useQuery({
    queryKey: ["admin-stats", "community", period],
    queryFn: () => fetchCommunityStats(period),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  return (
    <Section icon={Users} id="communaute" title="Communauté">
      {data ? <CommunityView stats={data} /> : <SectionState loading={isLoading} />}
    </Section>
  );
}

export function CommunityView({ stats }: { stats: CommunityStats }) {
  const { granularity } = stats.period;
  const per = granularity === "week" ? "par semaine" : "par mois";

  return (
    <div className="grid gap-5">
      <dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <KeyFigure label="Comptes créés" trend={stats.accountsCreated} value={formatCount(stats.accountsCreated.total)} />
        <KeyFigure label="Membres actifs" trend={stats.activePlayers} value={formatCount(stats.activePlayers.total)} />
        <KeyFigure label="Adhésions démarrées" trend={stats.membershipsStarted} value={formatCount(stats.membershipsStarted.total)} />
        <KeyFigure label="Amitiés acceptées" trend={stats.friendshipsAccepted} value={formatCount(stats.friendshipsAccepted.total)} />
        <KeyFigure label="Succès débloqués" trend={stats.achievementsUnlocked} value={formatCount(stats.achievementsUnlocked.total)} />
        <KeyFigure label="Comptes" value={formatCount(stats.accounts)} />
        <KeyFigure label="Adhérents à jour" value={formatCount(stats.members)} />
      </dl>
      <div className="grid gap-4 lg:grid-cols-2">
        <ChartBlock definition={`Comptes ${per}, à leur date de création (un compte supprimé depuis reste compté).`} title="Comptes créés">
          <TrendChart
            caption="Comptes créés"
            granularity={granularity}
            kind="bar"
            series={[{ key: "accounts", label: "Comptes créés", color: SERIES_COLOR, buckets: stats.accountsCreated.series }]}
          />
        </ChartBlock>
        <ChartBlock
          definition={`Comptes distincts ${per} dont un slot (à eux ou en co-joueur) a fait au moins un check. Mesuré depuis juillet 2026.`}
          title="Membres actifs"
        >
          <TrendChart
            caption="Membres actifs"
            granularity={granularity}
            kind="line"
            series={[{ key: "active", label: "Membres actifs", color: SERIES_COLOR, buckets: stats.activePlayers.series }]}
          />
        </ChartBlock>
      </div>
    </div>
  );
}

function PellesSection({ period }: { period: StatsPeriodCode }) {
  const { data, isLoading } = useQuery({
    queryKey: ["admin-stats", "pelles", period],
    queryFn: () => fetchPelleStats(period),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  return (
    <Section icon={Shovel} id="pelles" title="Pelles">
      {data ? <PellesView stats={data} /> : <SectionState loading={isLoading} />}
    </Section>
  );
}

export function PellesView({ stats }: { stats: PelleStats }) {
  const { granularity } = stats.period;

  return (
    <div className="grid gap-5">
      <dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <KeyFigure label="En circulation" value={<PelleAmount amount={stats.goldInCirculation} className="text-warning" />} />
        <KeyFigure label="Créées" trend={stats.created} value={<PelleAmount amount={stats.created.total} />} />
        <KeyFigure label="Détruites" trend={stats.destroyed} value={<PelleAmount amount={stats.destroyed.total} />} />
      </dl>
      <div className="grid gap-4 lg:grid-cols-2">
        <ChartBlock definition="Pelles d'or créées (crédits) et détruites (dépenses). Les pelles d'événement n'y figurent pas." title="Créées et détruites">
          <TrendChart
            caption="Pelles d'or créées et détruites"
            granularity={granularity}
            kind="bar"
            series={[
              { key: "created", label: "Créées", color: "var(--color-success)", buckets: stats.created.series },
              { key: "destroyed", label: "Détruites", color: "var(--color-danger)", buckets: stats.destroyed.series },
            ]}
          />
        </ChartBlock>
        <ChartBlock definition="Sur la période choisie." title="Par motif">
          {stats.byReason.length === 0 ? (
            <p className="text-sm text-muted-foreground">Aucun mouvement sur la période.</p>
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
                {stats.byReason.map((row) => (
                  <tr key={row.reason}>
                    <td className="py-2">{pelleReasonLabel(row.reason)}</td>
                    <td className="py-2 text-right tabular-nums">{formatCount(row.created)}</td>
                    <td className="py-2 text-right tabular-nums">{formatCount(row.destroyed)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </ChartBlock>
      </div>
    </div>
  );
}
