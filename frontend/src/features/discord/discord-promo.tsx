import type { ReactNode } from "react";
import { FaDiscord } from "react-icons/fa";

import { externalLinks } from "@/lib/external-links";

import type { DiscordStats } from "./discord-api";

/**
 * Story 30.50: the ArchiLAN Discord, put forward - in the header, on the home page and where it helps (an event's
 * questions, after a registration, looking for co-players). The counts come from Discord, when it answered.
 */

const NEW_TAB = { href: externalLinks.archilanDiscord, rel: "noopener noreferrer", target: "_blank" } as const;

function OnlineDot() {
  return <span aria-hidden className="size-2 shrink-0 rounded-full bg-success" />;
}

/**
 * The header button: the logo, and how many are online. No word: the header is capped at the shell width, and a
 * logged-in member already has the live badge, the bell and their menu there (the label says it all).
 */
export function DiscordHeaderLink({ stats, className = "" }: { stats: DiscordStats | null; className?: string }) {
  const label = stats ? `Rejoindre le Discord ArchiLAN, ${stats.online} membres en ligne (nouvel onglet)` : "Rejoindre le Discord ArchiLAN (nouvel onglet)";
  return (
    <a
      {...NEW_TAB}
      aria-label={label}
      className={`inline-flex min-h-10 shrink-0 items-center gap-2 rounded-lg border border-discord/60 bg-discord/15 px-2.5 text-sm font-semibold text-foreground transition-colors hover:bg-discord/25 ${className}`}
      title="Rejoindre le Discord ArchiLAN"
    >
      <FaDiscord aria-hidden className="size-5 text-discord-light" />
      {stats ? (
        <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
          <OnlineDot />
          {stats.online}
        </span>
      ) : null}
    </a>
  );
}

/** The header icon on a phone. */
export function DiscordIconLink() {
  return (
    <a
      {...NEW_TAB}
      aria-label="Discord ArchiLAN (nouvel onglet)"
      className="inline-flex size-11 items-center justify-center rounded border border-discord/60 bg-discord/15 text-discord-light"
    >
      <FaDiscord aria-hidden className="size-5" />
    </a>
  );
}

/** The filled call to join. */
export function DiscordJoinButton({ children = "Rejoindre le Discord", size = "md" }: { children?: ReactNode; size?: "md" | "lg" }) {
  return (
    <a
      {...NEW_TAB}
      className={`inline-flex items-center justify-center gap-2.5 rounded-lg bg-discord font-semibold text-white transition-colors hover:bg-discord-hover ${
        size === "lg" ? "min-h-12 px-5 text-[15px]" : "min-h-10 px-4 text-sm"
      }`}
    >
      <FaDiscord aria-hidden className={size === "lg" ? "size-5" : "size-[18px]"} />
      {children}
      <span className="sr-only"> (nouvel onglet)</span>
    </a>
  );
}

/** « 95 membres sur le Discord · 48 en ligne en ce moment ». */
export function DiscordStatsLine({ stats, compact = false }: { stats: DiscordStats | null; compact?: boolean }) {
  if (!stats) return null;
  if (compact) {
    return (
      <span className="inline-flex items-center gap-1.5 text-sm text-muted-foreground">
        <OnlineDot />
        {stats.online} en ligne · {stats.members} membres
      </span>
    );
  }
  return (
    <p className="flex flex-wrap items-center gap-x-1.5 text-sm text-muted-foreground">
      <strong className="font-semibold text-foreground">{stats.members} membres</strong> sur le Discord ·
      <span className="inline-flex items-center gap-1.5">
        <OnlineDot />
        <strong className="font-semibold text-foreground">{stats.online} en ligne</strong>
      </span>
      en ce moment
    </p>
  );
}

const SERVER_TOPICS = ["Les annonces des événements et des runs", "Trouver des co-joueurs pour une partie", "Les quêtes de la semaine, postées par le bot", "L'entraide sur les jeux et les YAML"];

/** The server as Discord shows it: name, counts, what happens there, and the way in. */
export function DiscordServerCard({ stats }: { stats: DiscordStats | null }) {
  return (
    <div className="overflow-hidden rounded-2xl border border-border bg-surface">
      <div className="h-14 bg-discord" />
      <div className="grid gap-5 px-6 pb-6">
        <div className="flex items-start gap-4">
          <span className="-mt-7 inline-flex size-16 shrink-0 items-center justify-center rounded-2xl bg-background ring-4 ring-surface">
            {/* eslint-disable-next-line @next/next/no-img-element -- a small local logo in a decorative card */}
            <img alt="" className="size-11" src="/images/logo.webp" />
          </span>
          <div className="pt-2">
            <h3 className="font-heading text-xl font-bold text-foreground">ArchiLAN</h3>
            {stats ? (
              <p className="flex gap-4 text-sm text-muted-foreground">
                <span className="inline-flex items-center gap-1.5">
                  <OnlineDot />
                  {stats.online} en ligne
                </span>
                <span>{stats.members} membres</span>
              </p>
            ) : null}
          </div>
        </div>
        <div className="grid gap-2.5">
          <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Ce qu&apos;on y fait</p>
          <ul className="grid gap-2 text-sm text-muted-foreground" role="list">
            {SERVER_TOPICS.map((topic) => (
              <li className="flex items-center gap-2.5" key={topic}>
                <span aria-hidden className="font-bold text-accent-text">
                  #
                </span>
                {topic}
              </li>
            ))}
          </ul>
        </div>
        <DiscordJoinButton>Rejoindre le serveur</DiscordJoinButton>
      </div>
    </div>
  );
}

/** A nudge where the Discord helps: an event's questions, a registration, a game looking for players. */
export function DiscordNudge({ title, children, cta, stats = null }: { title: string; children: ReactNode; cta: string; stats?: DiscordStats | null }) {
  return (
    <div className="flex items-start gap-4 rounded-lg border border-border bg-surface p-5">
      <span className="inline-flex size-12 shrink-0 items-center justify-center rounded-xl bg-discord/15 text-discord-light">
        <FaDiscord aria-hidden className="size-6" />
      </span>
      <div className="grid min-w-0 flex-1 gap-1.5">
        <p className="font-heading text-base font-semibold text-foreground">{title}</p>
        <p className="text-sm leading-6 text-muted-foreground">{children}</p>
        <div className="mt-2 flex flex-wrap items-center gap-3">
          <DiscordJoinButton>{cta}</DiscordJoinButton>
          <DiscordStatsLine compact stats={stats} />
        </div>
      </div>
    </div>
  );
}
