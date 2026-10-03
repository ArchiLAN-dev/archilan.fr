"use client";

import type { ReactNode } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { Calendar, Gamepad2, Shovel, Users } from "lucide-react";
import type { LucideIcon } from "lucide-react";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { PelleAmount } from "@/features/wallet/pelle-amount";
import { pelleReasonLabel } from "@/features/wallet/wallet-api";
import { KeyFigure, formatCount } from "./key-figure";
import {
  DEFAULT_STATS_PERIOD,
  STATS_PERIODS,
  fetchCommunityStats,
  fetchEventStats,
  fetchSessionStats,
  fetchPelleStats,
  parseStatsPeriod,
  type CommunityStats,
  type EventStats,
  type PelleStats,
  type SessionStats,
  type StatsBucket,
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
      <SessionsSection period={period} />
      <EventsSection period={period} />
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

function SessionsSection({ period }: { period: StatsPeriodCode }) {
  const { data, isLoading } = useQuery({
    queryKey: ["admin-stats", "sessions", period],
    queryFn: () => fetchSessionStats(period),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  return (
    <Section icon={Gamepad2} id="parties" title="Parties">
      {data ? <SessionsView stats={data} /> : <SectionState loading={isLoading} />}
    </Section>
  );
}

export function SessionsView({ stats }: { stats: SessionStats }) {
  const { granularity } = stats.period;

  return (
    <div className="grid gap-5">
      <dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <KeyFigure label="Runs lancées" trend={stats.runsLaunched} value={formatCount(stats.runsLaunched.total)} />
        <KeyFigure label="Runs créées" trend={stats.runsCreated} value={formatCount(stats.runsCreated.total)} />
        <KeyFigure label="Hebdos lancées" trend={stats.weeklyLaunched} value={formatCount(stats.weeklyLaunched.total)} />
        <KeyFigure label="Sessions d'événement" trend={stats.eventSessionsLaunched} value={formatCount(stats.eventSessionsLaunched.total)} />
        <KeyFigure label="Goals atteints" trend={stats.goalsReached} value={formatCount(stats.goalsReached.total)} />
        <KeyFigure label="Hebdos terminées" trend={stats.weeklyCompleted} value={formatCount(stats.weeklyCompleted.total)} />
        <KeyFigure label="Sessions en cours" value={formatCount(stats.runningSessions)} />
        <KeyFigure label="Runs actives" value={formatCount(stats.activeRuns)} />
      </dl>
      <div className="grid gap-4 lg:grid-cols-2">
        <ChartBlock
          definition="Runs privées lancées (une relance compte pour la même run), sessions d'événement démarrées, tentatives d'hebdo lancées."
          title="Lancements"
        >
          <TrendChart
            caption="Lancements de parties"
            granularity={granularity}
            kind="bar"
            series={[
              { key: "runs", label: "Runs privées", color: SERIES_COLOR, buckets: stats.runsLaunched.series },
              { key: "events", label: "Sessions d'événement", color: "var(--color-special)", buckets: stats.eventSessionsLaunched.series },
              { key: "weekly", label: "Hebdos", color: "var(--color-accent-warm)", buckets: stats.weeklyLaunched.series },
            ]}
          />
        </ChartBlock>
        <ChartBlock definition="Slots de session qui ont atteint leur goal, et tentatives d'hebdo terminées." title="Goals atteints">
          <TrendChart
            caption="Goals atteints"
            granularity={granularity}
            kind="line"
            series={[
              { key: "goals", label: "Sessions", color: SERIES_COLOR, buckets: stats.goalsReached.series },
              { key: "weekly", label: "Hebdos", color: "var(--color-accent-warm)", buckets: stats.weeklyCompleted.series },
            ]}
          />
        </ChartBlock>
      </div>
      <ChartBlock
        definition="Les 10 jeux qui comptent le plus de joueurs distincts ayant fait un check sur la période (co-joueurs compris). Les hebdos n'y figurent pas."
        title="Jeux les plus joués"
      >
        {stats.topGames.length === 0 ? (
          <p className="text-sm text-muted-foreground">Aucun check sur la période.</p>
        ) : (
          <table className="w-full text-left text-sm">
            <thead className="text-xs text-muted-foreground">
              <tr>
                <th className="py-1 font-medium">Jeu</th>
                <th className="py-1 text-right font-medium">Joueurs</th>
                <th className="py-1 text-right font-medium">Checks</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {stats.topGames.map((game) => (
                <tr key={game.gameId}>
                  <td className="py-2">{game.name}</td>
                  <td className="py-2 text-right tabular-nums">{formatCount(game.players)}</td>
                  <td className="py-2 text-right tabular-nums">{formatCount(game.checks)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </ChartBlock>
    </div>
  );
}

const euroFormatter = new Intl.NumberFormat("fr-FR", { style: "currency", currency: "EUR", maximumFractionDigits: 0 });
const dateFormatter = new Intl.DateTimeFormat("fr-FR", { dateStyle: "medium", timeZone: "Europe/Paris" });

const EVENT_STATUS_LABELS: Record<string, string> = {
  draft: "Brouillon",
  published: "Publié",
  "in-progress": "En cours",
  completed: "Terminé",
};

/** Cents to whole euros, for a chart whose axis reads in euros. */
function inEuros(buckets: StatsBucket[]): StatsBucket[] {
  return buckets.map((bucket) => ({ ...bucket, value: Math.round(bucket.value / 100) }));
}

function EventsSection({ period }: { period: StatsPeriodCode }) {
  const { data, isLoading } = useQuery({
    queryKey: ["admin-stats", "events", period],
    queryFn: () => fetchEventStats(period),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  return (
    <Section icon={Calendar} id="evenements" title="Événements">
      {data ? <EventsView stats={data} /> : <SectionState loading={isLoading} />}
    </Section>
  );
}

export function EventsView({ stats }: { stats: EventStats }) {
  const { granularity } = stats.period;

  return (
    <div className="grid gap-5">
      <dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <KeyFigure label="Inscriptions" trend={stats.registrations} value={formatCount(stats.registrations.total)} />
        <KeyFigure label="Annulations" trend={stats.cancellations} value={formatCount(stats.cancellations.total)} />
        <KeyFigure label="Recettes HelloAsso" trend={stats.revenue} value={euroFormatter.format(stats.revenue.total / 100)} />
        <KeyFigure label="Événements à venir" value={formatCount(stats.upcomingEvents)} />
      </dl>
      <div className="grid gap-4 lg:grid-cols-2">
        <ChartBlock
          definition="Inscriptions à leur date (annulées comprises), annulations à la date de leur dernière mise à jour."
          title="Inscriptions et annulations"
        >
          <TrendChart
            caption="Inscriptions et annulations"
            granularity={granularity}
            kind="bar"
            series={[
              { key: "registrations", label: "Inscriptions", color: SERIES_COLOR, buckets: stats.registrations.series },
              { key: "cancellations", label: "Annulations", color: "var(--color-special)", buckets: stats.cancellations.series },
            ]}
          />
        </ChartBlock>
        <ChartBlock definition="Commandes HelloAsso encaissées, en euros arrondis, à leur date de paiement." title="Recettes par formulaire">
          <TrendChart
            caption="Recettes HelloAsso en euros"
            granularity={granularity}
            kind="bar"
            series={[
              { key: "events", label: "Événements", color: SERIES_COLOR, buckets: inEuros(stats.revenueByType.events.series) },
              { key: "memberships", label: "Adhésions", color: "var(--color-accent-warm)", buckets: inEuros(stats.revenueByType.memberships.series) },
              { key: "shop", label: "Boutique", color: "var(--color-special)", buckets: inEuros(stats.revenueByType.shop.series) },
            ]}
          />
        </ChartBlock>
      </div>
      <ChartBlock definition="Les événements qui commencent dans la période ; inscriptions actives (hors annulations) rapportées à la jauge." title="Événements de la période">
        {stats.events.length === 0 ? (
          <p className="text-sm text-muted-foreground">Aucun événement sur la période.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="text-xs text-muted-foreground">
                <tr>
                  <th className="py-1 font-medium">Événement</th>
                  <th className="py-1 font-medium">Date</th>
                  <th className="py-1 font-medium">Statut</th>
                  <th className="py-1 text-right font-medium">Inscrits</th>
                  <th className="py-1 text-right font-medium">Remplissage</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {stats.events.map((event) => (
                  <tr key={event.eventId}>
                    <td className="py-2">{event.title}</td>
                    <td className="py-2 whitespace-nowrap">{dateFormatter.format(new Date(event.startsAt))}</td>
                    <td className="py-2">{EVENT_STATUS_LABELS[event.status] ?? event.status}</td>
                    <td className="py-2 text-right tabular-nums">
                      {formatCount(event.registrations)} / {formatCount(event.capacity)}
                    </td>
                    <td className="py-2 text-right tabular-nums">{event.fillRate} %</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </ChartBlock>
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
