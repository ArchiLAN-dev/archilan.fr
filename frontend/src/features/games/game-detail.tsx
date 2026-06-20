import Link from "next/link";
import { AlertTriangle, BookOpen, ExternalLink, Gamepad2, Settings2, ShieldAlert } from "lucide-react";
import { AdminEditLink } from "@/components/admin-edit-link";
import type { ArchipelagoClient } from "./archipelago-client-api";
import { availabilityConfig } from "./game-card";
import { GameContributionForm } from "./game-contribution-form";
import { GameOwnedBadge } from "./game-owned-badge";
import { InstallStepsView } from "./install-steps-view";
import type { GameApworld, PublicGameDetail } from "./public-games-api";

export function GameDetail({ game, client }: { game: PublicGameDetail; client: ArchipelagoClient | null }) {
  const status = availabilityConfig[game.availability] ?? availabilityConfig.available;
  const steamUrl = game.steamAppId !== null ? `https://store.steampowered.com/app/${game.steamAppId}` : null;

  return (
    <article className="mx-auto grid w-full max-w-6xl gap-12">
      <header className="grid gap-6 border-b border-border pb-10 md:grid-cols-[auto_1fr] md:gap-10">
        <div className="relative aspect-[3/4] w-full max-w-xs overflow-hidden rounded-lg bg-surface">
          {game.coverImageUrl ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              alt={game.coverImageAlt || game.name}
              className="h-full w-full object-cover"
              src={game.coverImageUrl}
            />
          ) : (
            <div className="flex h-full items-center justify-center bg-[linear-gradient(135deg,color-mix(in_oklab,var(--color-surface)_88%,var(--color-accent)),var(--color-background))]">
              <Gamepad2 aria-hidden="true" className="size-12 text-muted-foreground/40" />
            </div>
          )}
        </div>

        <div className="grid content-start gap-4">
          <div className="flex flex-wrap items-center gap-2">
            <span className={`rounded border px-2.5 py-1 text-sm font-semibold ${status.className}`}>
              {status.label}
            </span>
            {game.bundledWithAp ? (
              <span className="rounded border border-accent/50 bg-accent/10 px-2.5 py-1 text-sm font-semibold text-accent-text">
                Inclus dans Archipelago
              </span>
            ) : null}
            {game.adultContent ? (
              <span className="inline-flex items-center gap-1 rounded border border-danger/50 bg-danger/10 px-2.5 py-1 text-sm font-semibold text-danger">
                <ShieldAlert aria-hidden="true" className="size-4" />
                18+
              </span>
            ) : null}
            {game.steamAppId !== null ? <GameOwnedBadge steamAppId={game.steamAppId} /> : null}
            <AdminEditLink className="ml-auto" href={`/admin/jeux/${game.id}`} label="Modifier ce jeu" />
          </div>

          <h1 className="font-heading text-4xl font-bold leading-tight text-foreground md:text-5xl">
            {game.name}
          </h1>

          {game.description ? (
            <p className="text-lg leading-8 text-muted-foreground">{game.description}</p>
          ) : null}

          {game.platforms.length > 0 ? (
            <div className="flex flex-wrap gap-1.5">
              {game.platforms.map((platform) => (
                <span
                  className="rounded border border-border bg-surface px-2.5 py-1 text-sm text-muted-foreground"
                  key={platform}
                >
                  {platform}
                </span>
              ))}
            </div>
          ) : null}

          {game.coverImageCredit ? (
            <p className="text-xs text-muted-foreground">Image : {game.coverImageCredit}</p>
          ) : null}

          {steamUrl ? (
            <a
              className="inline-flex w-fit min-h-11 items-center justify-center gap-2 rounded border border-border bg-surface px-5 font-semibold text-foreground transition-colors hover:border-accent"
              href={steamUrl}
              rel="noopener noreferrer"
              target="_blank"
            >
              Voir sur Steam
              <ExternalLink aria-hidden="true" className="size-4" />
            </a>
          ) : null}
        </div>
      </header>

      <VersionMatchCallout apworld={game.apworld} bundled={game.bundledWithAp} client={client} />

      <Link
        className="inline-flex w-fit min-h-10 items-center gap-2 rounded border border-border px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
        href="/aide/archipelago"
      >
        <BookOpen aria-hidden="true" className="size-4" />
        Nouveau ? Débuter : installer Archipelago
      </Link>

      {game.installSteps.length > 0 ? (
        <section className="grid gap-5">
          <h2 className="font-heading text-2xl font-semibold text-foreground">Installation</h2>
          <InstallStepsView steps={game.installSteps} storageKey={`game-${game.slug}`} />
        </section>
      ) : null}

      <section className="grid gap-3 border-t border-border pt-8">
        <h2 className="font-heading text-lg font-semibold text-foreground">Améliorer ce tutoriel</h2>
        <p className="text-sm text-muted-foreground">
          Tu connais une meilleure procédure ? Propose une modification, l&apos;équipe la relira.
        </p>
        <GameContributionForm gameSlug={game.slug} initialSteps={game.installSteps} mode="game" />
      </section>

      {game.supportedEventTypes.length > 0 ? (
        <ChipSection title="Types d'événements" items={game.supportedEventTypes} />
      ) : null}

      {game.options.length > 0 ? (
        <section className="grid gap-5">
          <div className="flex items-center gap-3">
            <Settings2 aria-hidden="true" className="size-5 text-accent-warm" />
            <h2 className="font-heading text-2xl font-semibold text-foreground">Options de randomizer</h2>
          </div>
          <ul className="grid gap-2 sm:grid-cols-2">
            {game.options.map((option) => (
              <li
                className="flex items-center justify-between gap-3 rounded border border-border bg-surface px-4 py-2.5 text-sm"
                key={option.key}
              >
                <span className="font-medium text-foreground">{option.key}</span>
                <span className="text-muted-foreground">
                  {option.min}–{option.max}
                  {option.default !== null ? ` · défaut ${option.default}` : ""}
                </span>
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      {game.installSteps.length === 0
      && (game.catalog.links.length > 0 || game.catalog.notes || game.bundledWithAp) ? (
        <section className="grid gap-5">
          <h2 className="font-heading text-2xl font-semibold text-foreground">Liens & ressources</h2>
          {game.catalog.links.length > 0 || game.bundledWithAp ? (
            <ul className="grid gap-2">
              {game.bundledWithAp ? (
                <li>
                  <a
                    className="inline-flex items-center gap-2 text-accent-text underline-offset-2 hover:underline"
                    href="https://archipelago.gg/games"
                    rel="noopener noreferrer"
                    target="_blank"
                  >
                    Jeux supportés par Archipelago
                    <ExternalLink aria-hidden="true" className="size-3.5" />
                  </a>
                </li>
              ) : null}
              {game.catalog.links.map((link, index) =>
                link.url !== null ? (
                  <li key={`${link.label}-${index}`}>
                    <a
                      className="inline-flex items-center gap-2 text-accent-text underline-offset-2 hover:underline"
                      href={link.url}
                      rel="noopener noreferrer"
                      target="_blank"
                    >
                      {link.label}
                      <ExternalLink aria-hidden="true" className="size-3.5" />
                    </a>
                  </li>
                ) : (
                  <li className="text-sm text-muted-foreground" key={`${link.label}-${index}`}>
                    {link.label}
                  </li>
                ),
              )}
            </ul>
          ) : null}
          {game.catalog.notes ? (
            <p className="whitespace-pre-line text-sm leading-7 text-muted-foreground">{game.catalog.notes}</p>
          ) : null}
        </section>
      ) : null}
    </article>
  );
}

function VersionMatchCallout({
  apworld,
  bundled,
  client,
}: {
  apworld: GameApworld;
  bundled: boolean;
  client: ArchipelagoClient | null;
}) {
  const apworldDownload = apworld.releaseUrl ?? apworld.sourceUrl;
  const hasApworldInfo = bundled || apworld.deployedVersion !== null || apworldDownload !== null;

  if (!hasApworldInfo && client === null) {
    return null;
  }

  return (
    <section className="grid gap-3 rounded-lg border border-warning/40 bg-warning/5 p-5">
      <div className="flex items-center gap-2">
        <AlertTriangle aria-hidden="true" className="size-5 text-warning" />
        <h2 className="font-heading text-lg font-semibold text-foreground">Versions à installer</h2>
      </div>

      <dl className="grid gap-2 text-sm">
        {hasApworldInfo ? (
          <VersionRow label="Apworld">
            {bundled ? (
              <span className="text-muted-foreground">Inclus dans Archipelago — pas d&apos;apworld à installer.</span>
            ) : apworld.deployedVersion !== null ? (
              <VersionValue url={apworldDownload} version={apworld.deployedVersion} />
            ) : (
              <span className="text-muted-foreground">Version non figée — prends la dernière compatible.</span>
            )}
          </VersionRow>
        ) : null}
        <VersionRow label="Client Archipelago">
          {client !== null ? (
            <VersionValue url={client.downloadUrl} version={client.version} />
          ) : (
            <span className="text-muted-foreground">Non précisé — utilise la dernière version stable.</span>
          )}
        </VersionRow>
      </dl>

      <p className="text-sm leading-6 text-muted-foreground">
        Ton apworld <strong className="text-foreground">et</strong> ton client doivent correspondre à la
        version de la session, sinon la génération ou la connexion échoue.
      </p>
    </section>
  );
}

function VersionRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-wrap items-center gap-x-2">
      <dt className="font-medium text-foreground">{label} :</dt>
      <dd>{children}</dd>
    </div>
  );
}

function VersionValue({ version, url }: { version: string; url: string | null }) {
  return (
    <span className="inline-flex items-center gap-2">
      <span className="rounded border border-border bg-surface px-2 py-0.5 font-mono text-xs text-foreground">
        {version}
      </span>
      {url !== null ? (
        <a
          className="inline-flex items-center gap-1 text-accent-text underline-offset-2 hover:underline"
          href={url}
          rel="noopener noreferrer"
          target="_blank"
        >
          Télécharger
          <ExternalLink aria-hidden="true" className="size-3.5" />
        </a>
      ) : null}
    </span>
  );
}

function ChipSection({ title, items }: { title: string; items: string[] }) {
  return (
    <section className="grid gap-4">
      <h2 className="font-heading text-2xl font-semibold text-foreground">{title}</h2>
      <div className="flex flex-wrap gap-2">
        {items.map((item) => (
          <span
            className="rounded border border-border bg-surface px-3 py-1 text-sm text-muted-foreground"
            key={item}
          >
            {item}
          </span>
        ))}
      </div>
    </section>
  );
}