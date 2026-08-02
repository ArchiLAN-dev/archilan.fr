import { ArrowLeft, Clock, Trophy } from "lucide-react";
import Link from "next/link";

import { slotColorsByName } from "@/features/recap/build-checks-series";
import { buildRecapKeyFigures, hasUsableFlags } from "@/features/recap/build-key-figures";
import { buildPlayerRows, buildSlotLabels } from "@/features/recap/build-player-rows";
import { buildSendQuality, buildTopItems } from "@/features/recap/build-item-content";
import { ExchangeSankey, type ExchangeSlot } from "@/features/recap/exchange-sankey";
import { RecapItemContent } from "@/features/recap/recap-item-content";
import { RecapKeyFigures } from "@/features/recap/recap-key-figures";
import { RecapPlayerTable } from "@/features/recap/recap-player-table";
import { RunTimeline, type GoalMarker } from "@/features/recap/run-timeline";
import type { FeedEvent } from "@/features/recap/feed-api";
import { formatDuration } from "@/features/recap/recap-format";
import { RecapVod } from "@/features/recap/recap-vod";
import type { SessionRecap } from "@/features/recap/recap-api";

/** Enough to see a pattern, few enough to stay readable; the total is announced next to it. */
const TOP_ITEMS_LIMIT = 10;

export function SessionRecapView({ recap, feed }: { recap: SessionRecap; feed: FeedEvent[] }) {
  const slotLabels = buildSlotLabels(recap.podium);

  // One colour per slot, shared with the timeline below so a player keeps a single identity.
  const colorBySlotName = slotColorsByName(feed);
  const exchangeSlots: ExchangeSlot[] = recap.graph.nodes.map((node) => ({
    color: colorBySlotName.get(node.slotName) ?? "var(--chart-series-1)",
    game: node.game,
    slotId: node.slotId,
    slotName: node.slotName,
  }));

  const timeline = recap.podium
    .filter((slot) => slot.goalReachedAt !== null && !slot.isInvalidated)
    .sort((a, b) => (a.completionSeconds ?? 0) - (b.completionSeconds ?? 0));

  // Goal-reached instants for the timeline chart (story 32.9), keyed by AP slot name - the same name
  // the feed events carry, so the marker lands on the right curve.
  const goalMarkers: GoalMarker[] = recap.podium.flatMap((slot) =>
    slot.goalReachedAt !== null && !slot.isInvalidated ? [{ name: slot.slotName, at: slot.goalReachedAt }] : [],
  );

  const hasGraph = recap.graph.edges.length > 0;

  // The progression filter needs both sides: a projection that carries the counts (story 32.17) and
  // a run whose feed actually has AP flags (story 32.9). Missing either, the filter is not offered.
  const progressionAvailable =
    hasUsableFlags(feed) &&
    recap.graph.edges.every((edge) => edge.progressionCount !== undefined) &&
    recap.graph.localItems.every((local) => local.progressionCount !== undefined);

  return (
    <section className="mx-auto grid w-full max-w-content gap-10 px-4 py-10">
      <header className="grid gap-3">
        <Link
          className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
          href={`/runs/${recap.sessionId}/resultats`}
        >
          <ArrowLeft aria-hidden="true" className="size-3.5" />
          Retour aux résultats
        </Link>
        <p className="text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">Récap de partie</p>
        <h1 className="font-heading text-4xl font-bold leading-tight text-foreground">{recap.eventName}</h1>
        <div className="flex flex-wrap items-center gap-4 text-sm text-muted-foreground">
          {recap.durationSeconds !== null ? (
            <span className="inline-flex items-center gap-1.5">
              <Clock aria-hidden="true" className="size-4" />
              {formatDuration(recap.durationSeconds)}
            </span>
          ) : null}
          {recap.finishedAt !== null ? <span>{formatDate(recap.finishedAt)}</span> : null}
        </div>
      </header>

      <RecapKeyFigures figures={buildRecapKeyFigures(recap, feed)} />

      <div className="grid gap-4">
        <h2 className="flex items-center gap-2 font-heading text-2xl font-bold text-foreground">
          <Trophy aria-hidden="true" className="size-5 text-accent-text" />
          Les joueurs
        </h2>
        <RecapPlayerTable colorBySlotName={colorBySlotName} rows={buildPlayerRows(recap)} />
      </div>

      <div className="grid gap-4">
        <h2 className="font-heading text-2xl font-bold text-foreground">Qui a envoyé quoi à qui</h2>
        {hasGraph ? (
          <ExchangeSankey
            flows={recap.graph.edges}
            locals={recap.graph.localItems}
            progressionAvailable={progressionAvailable}
            slots={exchangeSlots}
          />
        ) : (
          <p className="rounded-lg border border-border bg-surface p-6 text-sm text-muted-foreground">
            Le graphe des échanges n&apos;est pas disponible pour cette partie.
          </p>
        )}
      </div>

      <RunTimeline events={feed} goals={goalMarkers} />

      <div className="grid gap-4">
        <h2 className="font-heading text-2xl font-bold text-foreground">Ce qui a circulé</h2>
        <RecapItemContent quality={buildSendQuality(feed)} topItems={buildTopItems(feed, TOP_ITEMS_LIMIT)} />
      </div>

      {timeline.length > 0 ? (
        <div className="grid gap-4">
          <h2 className="font-heading text-2xl font-bold text-foreground">Chronologie des objectifs</h2>
          <ol className="grid gap-2 border-l border-border pl-4">
            {timeline.map((slot) => (
              <li className="relative" key={slot.slotId}>
                <span className="absolute -left-[1.4rem] top-1.5 size-2.5 rounded-full bg-accent" aria-hidden="true" />
                <p className="text-sm font-semibold text-foreground">
                  {slotLabels.get(slot.slotId) ?? slot.playerName}
                </p>
                <p className="text-xs text-muted-foreground">
                  Objectif atteint en {formatDuration(slot.completionSeconds ?? 0)}
                </p>
              </li>
            ))}
          </ol>
        </div>
      ) : null}

      {recap.vodUrl !== null ? (
        <div className="grid gap-4">
          <h2 className="font-heading text-2xl font-bold text-foreground">Revoir la partie</h2>
          <RecapVod vodUrl={recap.vodUrl} />
        </div>
      ) : null}
    </section>
  );
}

export function SessionRecapNotFound() {
  return (
    <section className="mx-auto grid w-full max-w-content gap-4 px-4 py-16 text-center">
      <h1 className="font-heading text-3xl font-bold text-foreground">Récap indisponible</h1>
      <p className="text-sm text-muted-foreground">
        Cette partie n&apos;a pas de récap public - elle n&apos;est peut-être pas terminée, ou elle n&apos;est pas
        rattachée à un événement public.
      </p>
      <Link className="text-sm text-accent-text hover:underline" href="/">
        Retour à l&apos;accueil
      </Link>
    </section>
  );
}

function formatDate(iso: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "";
  return date.toLocaleDateString("fr-FR", { day: "numeric", month: "long", year: "numeric" });
}
