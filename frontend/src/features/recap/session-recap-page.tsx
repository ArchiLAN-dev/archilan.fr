import { ArrowLeft, Clock, Trophy } from "lucide-react";
import Link from "next/link";

import { ExchangeGraph, type GraphNode } from "@/features/recap/exchange-graph";
import { RecapVod } from "@/features/recap/recap-vod";
import type { SessionRecap } from "@/features/recap/recap-api";

const SUPERLATIVE_HINTS: Record<string, string> = {
  most_generous: "A envoyé le plus d'objets aux autres",
  biggest_hub: "A débloqué le plus de joueurs différents",
  first_to_goal: "Premier à atteindre son objectif",
  longest_road: "La plus longue route jusqu'au but",
};

export function SessionRecapView({ recap }: { recap: SessionRecap }) {
  const nameBySlot = new Map(recap.podium.map((slot) => [slot.slotId, slot.playerName]));

  const graphNodes: GraphNode[] = recap.graph.nodes.map((node) => {
    const sent = recap.graph.edges
      .filter((e) => e.fromSlotId === node.slotId)
      .reduce((sum, e) => sum + e.count, 0);
    const received = recap.graph.edges
      .filter((e) => e.toSlotId === node.slotId)
      .reduce((sum, e) => sum + e.count, 0);
    return {
      slotId: node.slotId,
      label: nameBySlot.get(node.slotId) ?? node.slotName,
      game: node.game,
      sent,
      received,
    };
  });

  const timeline = recap.podium
    .filter((slot) => slot.goalReachedAt !== null && !slot.isInvalidated)
    .sort((a, b) => (a.completionSeconds ?? 0) - (b.completionSeconds ?? 0));

  const hasGraph = recap.graph.edges.length > 0;

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

      {recap.superlatives.length > 0 ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {recap.superlatives.map((superlative) => (
            <div className="rounded-lg border border-border bg-surface p-4" key={superlative.key}>
              <p className="font-heading text-lg font-bold text-accent-text">{superlative.label}</p>
              <p className="mt-1 text-sm font-semibold text-foreground">
                {nameBySlot.get(superlative.slotId) ?? "-"}
              </p>
              <p className="mt-2 text-xs text-muted-foreground">
                {SUPERLATIVE_HINTS[superlative.key] ?? ""} - {formatSuperlativeValue(superlative.value)}
              </p>
            </div>
          ))}
        </div>
      ) : null}

      <div className="grid gap-4">
        <h2 className="font-heading text-2xl font-bold text-foreground">Qui a envoyé quoi à qui</h2>
        {hasGraph ? (
          <ExchangeGraph edges={recap.graph.edges} nodes={graphNodes} />
        ) : (
          <p className="rounded-lg border border-border bg-surface p-6 text-sm text-muted-foreground">
            Le graphe des échanges n&apos;est pas disponible pour cette partie.
          </p>
        )}
      </div>

      <div className="grid gap-4">
        <h2 className="flex items-center gap-2 font-heading text-2xl font-bold text-foreground">
          <Trophy aria-hidden="true" className="size-5 text-accent-text" />
          Podium
        </h2>
        <ol className="grid gap-2">
          {recap.podium.map((slot, index) => (
            <li
              className="flex items-center justify-between gap-4 rounded-lg border border-border bg-surface px-4 py-3"
              key={slot.slotId}
            >
              <div className="flex items-center gap-3">
                <span className="w-6 text-center font-heading text-lg font-bold text-muted-foreground">
                  {slot.isInvalidated ? "-" : index + 1}
                </span>
                <div>
                  <p className="font-semibold text-foreground">{slot.playerName}</p>
                  <p className="text-xs text-muted-foreground">{slot.game}</p>
                </div>
              </div>
              <span className="text-sm text-muted-foreground">
                {slot.completionSeconds !== null
                  ? formatDuration(slot.completionSeconds)
                  : slot.isInvalidated
                    ? "Invalidé"
                    : "Non terminé"}
              </span>
            </li>
          ))}
        </ol>
      </div>

      {timeline.length > 0 ? (
        <div className="grid gap-4">
          <h2 className="font-heading text-2xl font-bold text-foreground">Chronologie des objectifs</h2>
          <ol className="grid gap-2 border-l border-border pl-4">
            {timeline.map((slot) => (
              <li className="relative" key={slot.slotId}>
                <span className="absolute -left-[1.4rem] top-1.5 size-2.5 rounded-full bg-accent" aria-hidden="true" />
                <p className="text-sm font-semibold text-foreground">{slot.playerName}</p>
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

function formatDuration(totalSeconds: number): string {
  const hours = Math.floor(totalSeconds / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;
  if (hours > 0) return `${hours} h ${minutes.toString().padStart(2, "0")} min`;
  if (minutes > 0) return `${minutes} min ${seconds.toString().padStart(2, "0")} s`;
  return `${seconds} s`;
}

function formatDate(iso: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "";
  return date.toLocaleDateString("fr-FR", { day: "numeric", month: "long", year: "numeric" });
}

function formatSuperlativeValue(value: number | string): string {
  if (typeof value === "number") return `${value} objets`;
  const date = new Date(value);
  if (!Number.isNaN(date.getTime())) {
    return date.toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit" });
  }
  return value;
}
