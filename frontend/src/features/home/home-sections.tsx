import Image from "next/image";
import Link from "next/link";
import type { ReactNode } from "react";
import { ArrowRight, Check, ScrollText } from "lucide-react";

import type { CommunityStats } from "@/features/community/community-api";
import type { PublicPost } from "@/features/content/content-types";
import type { DiscordStats } from "@/features/discord/discord-api";
import { DiscordJoinButton } from "@/features/discord/discord-promo";
import type { PublicEvent } from "@/features/events/event-types";
import { SUPPORT_TAB_HREF } from "@/features/payments/support-archilan";
import { formatDuration } from "@/features/recap/recap-format";
import type { CurrentWeeklyRun } from "@/features/weekly-runs/weekly-runs-api";

import type { HomeRecap } from "./home-api";

/** Story 34.9: the sections of the home page, newcomer first. Each one folds away when it has nothing real to show. */

const NUMBER = new Intl.NumberFormat("fr-FR");
const MONTH_YEAR = new Intl.DateTimeFormat("fr-FR", { month: "short", year: "numeric" });
const DAY_MONTH_YEAR = new Intl.DateTimeFormat("fr-FR", { day: "numeric", month: "long", year: "numeric" });

function Eyebrow({ children, special = false }: { children: ReactNode; special?: boolean }) {
  return (
    <p className={`text-sm font-semibold uppercase tracking-[0.18em] ${special ? "text-[color:var(--color-special)]" : "text-accent-text"} text-on-canvas`}>
      {children}
    </p>
  );
}

function SectionTitle({ id, children }: { id: string; children: ReactNode }) {
  return (
    <h2 className="font-heading text-3xl font-bold text-foreground md:text-4xl text-on-canvas" id={id}>
      {children}
    </h2>
  );
}

function MoreLink({ href, children }: { href: string; children: ReactNode }) {
  return (
    <Link className="inline-flex items-center gap-1 text-sm font-semibold text-accent-text hover:text-accent-text-hover" href={href}>
      {children}
      <ArrowRight aria-hidden className="size-4" />
    </Link>
  );
}

/** Under the hero buttons: proof that the site is alive this week. */
export function HomeNow({ weeklyRuns, discord, nextEvent }: { weeklyRuns: number; discord: DiscordStats | null; nextEvent: PublicEvent | null }) {
  const chip = "inline-flex min-h-8 items-center gap-2 rounded-full border border-border bg-surface/85 px-3 text-[13px] text-muted-foreground";
  return (
    <ul className="flex flex-wrap gap-2" role="list">
      {weeklyRuns > 0 ? (
        <li className={chip}>
          <span aria-hidden className="size-2 rounded-full bg-success" />
          <strong className="font-semibold text-foreground">
            {weeklyRuns} {weeklyRuns > 1 ? "runs hebdos ouvertes" : "run hebdo ouverte"}
          </strong>
          cette semaine
        </li>
      ) : null}
      {discord ? (
        <li className={chip}>
          <strong className="font-semibold text-foreground">{discord.online} en ligne</strong> sur le Discord
        </li>
      ) : null}
      <li className={chip}>
        Prochaine LAN :<strong className="font-semibold text-foreground">{nextEvent ? nextEvent.date : "bientôt annoncée"}</strong>
      </li>
    </ul>
  );
}

/** The multiworld told to someone who never heard of it, over three real games of the week. */
export function HomeConcept({ covers }: { covers: { name: string; url: string }[] }) {
  const [a, b, c] = covers;
  return (
    <section aria-labelledby="concept-heading" className={`grid items-center gap-12 ${a && b && c ? "lg:grid-cols-2" : ""}`}>
      <div className="grid max-w-3xl gap-4">
        <Eyebrow>Le concept</Eyebrow>
        <SectionTitle id="concept-heading">Chacun son jeu, les objets de tout le monde</SectionTitle>
        <p className="text-lg leading-8 text-muted-foreground text-on-canvas">
          Dans un multiworld Archipelago, chaque joueur lance son propre jeu. Les objets sont mélangés entre tous les jeux :
          en ouvrant un coffre chez toi, tu peux trouver le grappin d&apos;un autre joueur, et c&apos;est peut-être lui qui
          détient tes bottes. On avance ensemble, et la partie se gagne quand chacun a atteint son objectif.
        </p>
        <p className="text-base leading-7 text-muted-foreground text-on-canvas">
          Aucune connaissance requise : des centaines de jeux sont compatibles, et le site t&apos;aide à préparer ta
          configuration.
        </p>
      </div>
      {a && b && c ? (
        <div className="grid grid-cols-3 items-center gap-3 sm:gap-4">
          <CoverFigure caption="Toi" cover={a} />
          <CoverFigure caption="Un objet pour toi est caché ici" cover={b} highlight />
          <CoverFigure caption="Un autre joueur" cover={c} />
        </div>
      ) : null}
    </section>
  );
}

function CoverFigure({ cover, caption, highlight = false }: { cover: { name: string; url: string }; caption: string; highlight?: boolean }) {
  return (
    <figure className={`grid justify-items-center gap-2.5 ${highlight ? "-translate-y-4" : ""}`}>
      <Image
        alt={`Jaquette de ${cover.name}`}
        className={`aspect-[3/4] w-full rounded-xl object-cover ${highlight ? "border-2 border-accent-text shadow-[0_0_28px_rgba(149,128,245,0.35)]" : "border border-border"}`}
        height={352}
        sizes="(min-width: 1024px) 200px, 30vw"
        src={cover.url}
        width={264}
      />
      <figcaption className={`text-center text-[13px] ${highlight ? "font-semibold text-foreground" : "text-muted-foreground"}`}>{caption}</figcaption>
    </figure>
  );
}

const STEPS = [
  { title: "Crée ton compte", text: "Gratuit. Ton profil garde tes parties, tes succès et tes cosmétiques.", href: "/inscription", cta: "Créer mon compte" },
  {
    title: "Choisis ton jeu",
    text: "Parcours le catalogue des jeux compatibles. Le site prépare ta configuration (le YAML) avec toi, option par option.",
    href: "/jeux",
    cta: "Voir les jeux",
  },
  {
    title: "Joue",
    text: "Une run hebdo pour te lancer en solo, une partie privée entre amis, ou une LAN avec toute la communauté.",
    href: "#cette-semaine",
    cta: "Voir les runs de la semaine",
  },
] as const;

export function HomeStartSteps() {
  return (
    <section aria-labelledby="start-heading" className="grid gap-8 scroll-mt-24" id="commencer">
      <div className="grid gap-3">
        <Eyebrow>Je commence</Eyebrow>
        <SectionTitle id="start-heading">Ta première partie en trois étapes</SectionTitle>
      </div>
      <ol className="grid gap-4 md:grid-cols-3">
        {STEPS.map((step, index) => (
          <li className="card-glow flex flex-col gap-3 rounded-2xl border border-border bg-surface p-6" key={step.title}>
            <span aria-hidden className="inline-flex size-10 items-center justify-center rounded-xl bg-accent font-heading text-lg font-bold text-white">
              {index + 1}
            </span>
            <h3 className="font-heading text-xl font-semibold text-foreground">{step.title}</h3>
            <p className="text-[15px] leading-7 text-muted-foreground">{step.text}</p>
            <span className="mt-auto">
              <MoreLink href={step.href}>{step.cta}</MoreLink>
            </span>
          </li>
        ))}
      </ol>
    </section>
  );
}

export function HomeThisWeek({ runs }: { runs: CurrentWeeklyRun[] }) {
  const week = runs[0]?.weekNumber;
  return (
    <section aria-labelledby="week-heading" className="grid gap-8 scroll-mt-24" id="cette-semaine">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="grid gap-3">
          <Eyebrow>Cette semaine{week ? ` · semaine ${week}` : ""}</Eyebrow>
          <SectionTitle id="week-heading">Les runs hebdos</SectionTitle>
          <p className="text-base text-muted-foreground text-on-canvas">Une seed par jeu, la même pour tout le monde : fais ton meilleur temps avant dimanche.</p>
        </div>
        <MoreLink href="/runs-hebdo">Toutes les runs hebdos</MoreLink>
      </div>
      {runs.length > 0 ? (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {runs.map((run) => (
            <article className="flex gap-4 rounded-2xl border border-border bg-surface p-4" key={run.weeklyRunId}>
              {run.coverImageUrl ? (
                <Image alt="" className="h-[118px] w-[88px] shrink-0 rounded-lg object-cover" height={118} src={run.coverImageUrl} width={88} />
              ) : (
                <span aria-hidden className="h-[118px] w-[88px] shrink-0 rounded-lg bg-surface-2" />
              )}
              <div className="flex min-w-0 flex-col gap-1.5">
                <h3 className="font-heading text-lg font-semibold text-foreground">{run.templateName ?? run.gameName}</h3>
                <p className="text-sm text-muted-foreground">{run.gameName}</p>
                <p className={`text-[13px] ${run.participants.length === 0 ? "text-success" : "text-muted-foreground"}`}>
                  {run.participants.length === 0
                    ? "Personne encore : prends la première place"
                    : `${run.participants.length} ${run.participants.length > 1 ? "joueurs" : "joueur"} cette semaine`}
                </p>
                <Link
                  className="mt-auto inline-flex min-h-9 items-center self-start rounded-lg bg-accent px-3.5 text-[13px] font-semibold text-white hover:bg-accent-hover"
                  href={`/runs-hebdo/${run.weeklyRunId}`}
                >
                  Jouer
                </Link>
              </div>
            </article>
          ))}
        </div>
      ) : (
        <p className="rounded-2xl border border-border bg-surface p-6 text-muted-foreground">Les runs de la semaine arrivent lundi.</p>
      )}
      <div className="flex flex-wrap items-center gap-5 rounded-2xl border border-amber-400/35 bg-amber-400/5 px-6 py-5">
        <span className="inline-flex size-12 shrink-0 items-center justify-center rounded-xl bg-amber-400/15 text-amber-400">
          <ScrollText aria-hidden className="size-6" />
        </span>
        <div className="grid min-w-0 flex-[1_1_320px] gap-1">
          <p className="font-heading text-lg font-semibold text-foreground">Les quêtes de la semaine</p>
          <p className="text-[15px] leading-6 text-muted-foreground">
            Joue, termine des quêtes et gagne des pelles, à dépenser en boutique : cadres d&apos;avatar animés, bannières,
            titres et couleurs de pseudo.
          </p>
        </div>
        <Link className="inline-flex min-h-11 items-center rounded-lg border border-amber-400/60 px-4 text-sm font-semibold text-amber-400 hover:bg-amber-400/10" href="/compte/portefeuille">
          Voir les quêtes
        </Link>
      </div>
    </section>
  );
}

function confirmed(event: PublicEvent): number | null {
  return event.capacity ? event.capacity.total - event.capacity.remaining : (event.stats?.players ?? null);
}

/** The next LAN if one is announced, otherwise the last one; and how the editions grew. `past` is newest first. */
export function HomeLan({ upcoming, past }: { upcoming: PublicEvent[]; past: PublicEvent[] }) {
  const next = upcoming[0] ?? null;
  const featured = next ?? past[0] ?? null;
  if (featured === null) return null;
  const editions = past.slice(0, 4).reverse();

  return (
    <section aria-labelledby="lan-heading" className="grid items-center gap-10 lg:grid-cols-2">
      <Link className="relative block overflow-hidden rounded-2xl border border-border" href={`/evenements/${featured.id}`}>
        {featured.coverImageUrl ? (
          <Image alt={featured.title} className="aspect-[16/10] w-full object-cover" height={600} sizes="(min-width: 1024px) 600px, 100vw" src={featured.coverImageUrl} width={960} />
        ) : (
          <span aria-hidden className="block aspect-[16/10] w-full bg-surface-2" />
        )}
        <span className="absolute bottom-4 left-4 rounded-lg bg-background/85 px-3 py-1.5 text-[13px] text-foreground">
          {featured.title} · {featured.date}
        </span>
      </Link>
      <div className="grid gap-5">
        <Eyebrow>Les LAN</Eyebrow>
        <SectionTitle id="lan-heading">Un week-end, une seule partie, tout le monde dans la même salle</SectionTitle>
        {editions.length > 1 ? (
          <ol className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {editions.map((event, index) => {
              const players = confirmed(event);
              const last = index === editions.length - 1;
              return (
                <li className={`grid gap-1 rounded-xl border bg-surface p-3.5 ${last ? "border-accent-text" : "border-border"}`} key={event.id}>
                  <span className="text-[13px] text-muted-foreground">
                    {event.title.replace(/^ArchiLAN\s*/, "") || event.title} · {event.dateIso ? MONTH_YEAR.format(new Date(event.dateIso)) : event.date}
                  </span>
                  {players !== null ? (
                    <>
                      <span className="font-heading text-2xl font-bold text-foreground">{players}</span>
                      <span className="text-[13px] text-muted-foreground">joueurs</span>
                    </>
                  ) : null}
                </li>
              );
            })}
          </ol>
        ) : null}
        {next ? (
          <>
            <p className="text-base leading-7 text-muted-foreground text-on-canvas">
              <strong className="text-foreground">{next.title}</strong> : {next.dateIso ? DAY_MONTH_YEAR.format(new Date(next.dateIso)) : next.date}, {next.location}.
              {next.capacity ? ` ${next.capacity.remaining} ${next.capacity.remaining > 1 ? "places restantes" : "place restante"}.` : ""}
            </p>
            <div className="flex flex-wrap gap-3">
              <Link className="inline-flex min-h-12 items-center rounded-lg bg-accent px-5 font-semibold text-white hover:bg-accent-hover" href={`/evenements/${next.id}`}>
                Voir l&apos;événement
              </Link>
              <DiscordJoinButton size="lg">Suivre sur Discord</DiscordJoinButton>
            </div>
          </>
        ) : (
          <>
            <p className="text-base leading-7 text-muted-foreground text-on-canvas">
              La prochaine édition n&apos;est pas encore annoncée. Les places partent vite : le Discord est prévenu en premier.
            </p>
            <div className="flex flex-wrap gap-3">
              <DiscordJoinButton size="lg">Être prévenu sur Discord</DiscordJoinButton>
              <Link className="inline-flex min-h-12 items-center rounded-lg border border-border px-5 font-semibold text-foreground hover:border-accent" href="/evenements">
                Revoir les éditions
              </Link>
            </div>
          </>
        )}
      </div>
    </section>
  );
}

export function HomeRecaps({ recaps }: { recaps: HomeRecap[] }) {
  if (recaps.length === 0) return null;
  return (
    <section aria-labelledby="recaps-heading" className="grid gap-8">
      <div className="grid gap-3">
        <Eyebrow>Ce qui s&apos;est passé</Eyebrow>
        <SectionTitle id="recaps-heading">Les dernières parties racontées</SectionTitle>
      </div>
      <div className="grid gap-4 md:grid-cols-2">
        {recaps.map((recap) => (
          <article className="grid gap-3 rounded-2xl border border-border bg-surface p-6" key={recap.sessionId}>
            <p className="text-[13px] text-muted-foreground">{recap.eventTitle}</p>
            <h3 className="font-heading text-lg font-semibold text-foreground">
              {recap.playerCount} joueurs{recap.durationSeconds !== null ? `, terminée en ${formatDuration(recap.durationSeconds)}` : ""}
            </h3>
            {recap.winner ? (
              <p className="text-sm text-muted-foreground">
                Premier au but : <strong className="text-amber-400">{recap.winner.playerName}</strong> ({recap.winner.game})
              </p>
            ) : null}
            <MoreLink href={`/parties/${recap.sessionId}`}>Lire le récap</MoreLink>
          </article>
        ))}
      </div>
    </section>
  );
}

export function HomeCommunity({ stats, discord }: { stats: CommunityStats | null; discord: DiscordStats | null }) {
  const figures = [
    stats ? { label: "parties terminées", value: stats.totalFinishedSessions } : null,
    stats ? { label: "checks réalisés", value: stats.totalChecksDone } : null,
    stats ? { label: "objectifs atteints", value: stats.totalGoalsReached } : null,
    discord ? { label: "membres sur le Discord", value: discord.members } : null,
  ].filter((f): f is { label: string; value: number } => f !== null);

  return (
    <section aria-labelledby="community-heading" className="grid gap-8">
      <div className="grid gap-3">
        <Eyebrow>La communauté</Eyebrow>
        <SectionTitle id="community-heading">Ensemble, on a déjà joué</SectionTitle>
      </div>
      {figures.length > 0 ? (
        <dl className="grid grid-cols-2 gap-4 lg:grid-cols-4">
          {figures.map((figure) => (
            <div className="rounded-2xl border border-border bg-surface p-5" key={figure.label}>
              <dt className="text-sm text-muted-foreground">{figure.label}</dt>
              <dd className="mt-1.5 font-heading text-4xl font-bold text-foreground">{NUMBER.format(figure.value)}</dd>
            </div>
          ))}
        </dl>
      ) : null}
      <div className="flex flex-wrap items-center gap-5 rounded-2xl border border-discord/50 bg-discord/10 p-6">
        <div className="grid min-w-0 flex-[1_1_360px] gap-1.5">
          <p className="font-heading text-xl font-semibold text-foreground">La communauté vit sur Discord</p>
          <p className="text-[15px] leading-6 text-muted-foreground">
            Annonces, recherche de co-joueurs, quêtes de la semaine et entraide sur les YAML.
            {discord ? ` ${discord.online} personnes en ligne en ce moment.` : ""}
          </p>
        </div>
        <DiscordJoinButton size="lg" />
      </div>
    </section>
  );
}

const MEMBER_PERKS = [
  { title: "Des cosmétiques réservés aux adhérents", text: "cadres, bannières et titres." },
  { title: "Tu soutiens les LAN", text: "salle, matériel et serveurs de parties." },
  { title: "Tu fais vivre le site", text: "développé et hébergé par des bénévoles." },
] as const;

export function HomeAssociation() {
  return (
    <section aria-labelledby="asso-heading" className="grid items-start gap-10 rounded-3xl border border-border bg-surface p-6 sm:p-10 lg:grid-cols-2">
      <div className="grid gap-4">
        <Eyebrow special>L&apos;association</Eyebrow>
        <h2 className="font-heading text-2xl font-bold text-foreground md:text-3xl" id="asso-heading">
          ArchiLAN est portée par une association à Clermont-Ferrand
        </h2>
        <p className="text-base leading-7 text-muted-foreground">
          Des bénévoles organisent les LAN, font tourner les serveurs de parties et développent ce site. Adhérer, c&apos;est
          faire vivre tout ça.
        </p>
        <div className="mt-1 flex flex-wrap gap-3">
          <Link className="inline-flex min-h-12 items-center rounded-lg bg-accent px-5 font-semibold text-white hover:bg-accent-hover" href={`${SUPPORT_TAB_HREF}#adhesion`}>
            Adhérer
          </Link>
          <Link className="inline-flex min-h-12 items-center rounded-lg border border-border px-5 font-semibold text-foreground hover:border-accent" href={`${SUPPORT_TAB_HREF}#don`}>
            Faire un don
          </Link>
        </div>
      </div>
      <ul className="grid gap-3.5" role="list">
        {MEMBER_PERKS.map((perk) => (
          <li className="flex items-start gap-3.5" key={perk.title}>
            <span className="inline-flex size-7 shrink-0 items-center justify-center rounded-full bg-success/15 text-success">
              <Check aria-hidden className="size-4" strokeWidth={3} />
            </span>
            <span className="text-[15px] leading-6 text-muted-foreground">
              <strong className="text-foreground">{perk.title}</strong> : {perk.text}
            </span>
          </li>
        ))}
      </ul>
    </section>
  );
}

export function HomeNews({ posts }: { posts: PublicPost[] }) {
  if (posts.length === 0) return null;
  return (
    <section aria-labelledby="news-heading" className="grid gap-8">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="grid gap-3">
          <Eyebrow>Actualités</Eyebrow>
          <SectionTitle id="news-heading">Les dernières nouvelles</SectionTitle>
        </div>
        <MoreLink href="/actualites">Toutes les actualités</MoreLink>
      </div>
      <div className="grid gap-4 md:grid-cols-3">
        {posts.slice(0, 3).map((post) => (
          <Link className="card-glow grid gap-2 rounded-2xl border border-border bg-surface p-5 transition-colors hover:border-accent" href={`/actualites/${post.slug}`} key={post.slug}>
            <span className="text-[13px] text-muted-foreground">{DAY_MONTH_YEAR.format(new Date(post.publishedAt))}</span>
            <span className="font-heading text-lg font-semibold text-foreground">{post.title}</span>
            <span className="line-clamp-3 text-sm leading-6 text-muted-foreground">{post.excerpt}</span>
          </Link>
        ))}
      </div>
    </section>
  );
}
