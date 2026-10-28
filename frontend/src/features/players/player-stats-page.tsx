import Link from "next/link";
import { ArrowLeft, Trophy } from "lucide-react";

import { formatDuration } from "@/features/recap/recap-format";
import type { GameStatRow, PlayerStatsPage, StatFact, StatGroup } from "./player-stats-api";

/** Groups whose numbers only exist since the bridge started reporting them (stories 32.25 and 32.26). */
const GROUP_NOTES: Record<string, string> = {
  playtime: "Temps où un client de jeu était connecté, AFK compris. Compté seulement sur les parties lancées depuis la mise en service du suivi.",
  deathlink: "Seules les morts partagées par DeathLink sont vues, sur les parties lancées depuis la mise en service du suivi. Les joueurs emportés sont une estimation.",
};

/**
 * Everything the site tracks about a member's play (story 30.55): the facts the achievements read, grouped by
 * theme, the recap superlatives and a table by game. Pure render: the update date is absolute, never "il y a",
 * because a relative label computed from the server clock would drift against the reader's on hydration.
 */
export function PlayerStatsPageView({ stats }: { stats: PlayerStatsPage }) {
  const name = stats.displayName ?? stats.slug;
  const wonSuperlatives = stats.superlatives.filter((superlative) => superlative.value > 0);

  return (
    <article className="mx-auto grid max-w-content gap-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <Link
          className="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
          href={`/joueurs/${stats.slug}`}
        >
          <ArrowLeft aria-hidden className="size-3.5" />
          Retour au profil
        </Link>
        <Link
          className="inline-flex w-fit items-center gap-1.5 text-sm text-accent-text transition-colors hover:underline"
          href={`/joueurs/${stats.slug}/succes`}
        >
          <Trophy aria-hidden className="size-3.5" />
          Voir les succès
        </Link>
      </div>

      <header className="rounded-lg border border-border bg-surface p-5">
        <h1 className="font-heading text-2xl font-bold text-foreground">Stats de {name}</h1>
        <p className="mt-1 text-sm text-muted-foreground">{updatedLabel(stats.computedAt)}</p>
      </header>

      <div className="grid gap-4 md:grid-cols-2">
        {stats.groups.map((group) => (
          <StatGroupCard group={group} key={group.key} />
        ))}
      </div>

      {wonSuperlatives.length > 0 ? (
        <section className="grid gap-3 rounded-lg border border-border bg-surface p-5">
          <h2 className="font-heading text-lg font-semibold text-foreground">Superlatifs de recap</h2>
          <ul className="flex flex-wrap gap-2">
            {wonSuperlatives.map((superlative) => (
              <li
                className="rounded-full border border-accent/40 bg-accent/10 px-3 py-1 text-sm text-accent-text"
                key={superlative.key}
              >
                {superlative.label} <span className="font-semibold tabular-nums">x{superlative.value}</span>
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      <GamesTable games={stats.games} />
    </article>
  );
}

function StatGroupCard({ group }: { group: StatGroup }) {
  const note = GROUP_NOTES[group.key];

  return (
    <section className="grid content-start gap-3 rounded-lg border border-border bg-surface p-5">
      <h2 className="font-heading text-lg font-semibold text-foreground">{group.label}</h2>
      <dl className="grid gap-2">
        {group.facts.map((fact) => (
          <div className="flex items-baseline justify-between gap-4" key={fact.key}>
            <dt className="text-sm text-muted-foreground">{fact.label}</dt>
            <dd className="font-heading text-lg font-bold tabular-nums text-foreground">{formatFact(fact)}</dd>
          </div>
        ))}
      </dl>
      {note ? <p className="text-xs text-muted-foreground">{note}</p> : null}
    </section>
  );
}

function GamesTable({ games }: { games: GameStatRow[] }) {
  if (games.length === 0) {
    return null;
  }

  return (
    <section className="grid gap-3">
      <h2 className="font-heading text-lg font-semibold text-foreground">Par jeu</h2>
      <div className="overflow-x-auto rounded-lg border border-border bg-surface">
        <table className="w-full min-w-[36rem] border-collapse text-sm">
          <thead>
            <tr className="border-b border-border text-left text-xs uppercase tracking-[0.12em] text-muted-foreground">
              <th className="px-4 py-3 font-medium">Jeu</th>
              <th className="px-4 py-3 text-right font-medium">Parties</th>
              <th className="px-4 py-3 text-right font-medium">Objectifs</th>
              <th className="px-4 py-3 text-right font-medium">Checks</th>
              <th className="px-4 py-3 text-right font-medium">Connecté</th>
              <th className="px-4 py-3 text-right font-medium">Morts</th>
            </tr>
          </thead>
          <tbody>
            {games.map((game) => (
              <tr className="border-b border-border/60 last:border-b-0" key={game.gameId}>
                <td className="px-4 py-3 font-semibold text-foreground">
                  {game.slug ? (
                    <Link className="hover:underline" href={`/jeux/${game.slug}`}>
                      {game.name}
                    </Link>
                  ) : (
                    game.name
                  )}
                </td>
                <td className="px-4 py-3 text-right tabular-nums text-foreground">{game.runs}</td>
                <td className="px-4 py-3 text-right tabular-nums text-foreground">{game.goals}</td>
                <td className="px-4 py-3 text-right tabular-nums text-foreground">{game.checks}</td>
                <td className="px-4 py-3 text-right tabular-nums text-muted-foreground">
                  {game.playedSeconds > 0 ? formatDuration(game.playedSeconds) : "-"}
                </td>
                <td className="px-4 py-3 text-right tabular-nums text-muted-foreground">{game.deaths > 0 ? game.deaths : "-"}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="text-xs text-muted-foreground">
        Une partie où le joueur tient plusieurs jeux compte une fois dans chacun d&apos;eux.
      </p>
    </section>
  );
}

export function formatFact(fact: StatFact): string {
  if (fact.unit === "seconds") {
    return fact.value > 0 ? formatDuration(fact.value) : "-";
  }
  return new Intl.NumberFormat("fr-FR").format(fact.value);
}

function updatedLabel(computedAt: string | null): string {
  if (computedAt === null) {
    return "Pas encore calculé : les stats arrivent avec le prochain calcul des succès (toutes les heures).";
  }
  const date = new Date(computedAt);
  if (Number.isNaN(date.getTime())) {
    return "";
  }
  const formatted = new Intl.DateTimeFormat("fr-FR", {
    day: "numeric",
    month: "long",
    hour: "2-digit",
    minute: "2-digit",
    timeZone: "Europe/Paris",
  }).format(date);

  return `Mis à jour le ${formatted}. Recalculé toutes les heures et après chaque recap.`;
}
