"use client";

import { useEffect, useState } from "react";
import { CheckCircle2, Loader2, TriangleAlert, XCircle } from "lucide-react";

import { GenerationFailureNotice } from "@/features/games/generation-failure-notice";
import { getGameYamlTest, startGameYamlTest, type TestedWorldSize, type YamlTestContext } from "./game-yaml-test-api";

const POLL_MS = 3000;
/** The orchestrator gives up on a test generation after a few minutes; stop polling well after that. */
const MAX_POLLS = 200;

export type GameYamlTestState =
  | { kind: "idle" }
  | { kind: "starting" }
  | { kind: "running"; ticket: string }
  | { kind: "done"; status: "passed" | "failed"; error: string | null; world?: TestedWorldSize | null }
  | { kind: "error"; message: string };

/**
 * Story 9.57: runs a test generation of a YAML and follows it until its verdict. `start` is an event handler;
 * the polling lives in an effect keyed by the ticket, so it stops when the editor unmounts.
 */
export function useGameYamlTest(gameId: string | undefined, context?: YamlTestContext) {
  const [state, setState] = useState<GameYamlTestState>({ kind: "idle" });
  const ticket = state.kind === "running" ? state.ticket : null;
  // Story 56.5: a stable key, so a new context object on each render does not restart the polling.
  const contextKey = context === undefined ? "" : "eventId" in context ? `e:${context.eventId}` : `r:${context.runId}`;

  useEffect(() => {
    if (ticket === null || gameId === undefined) return;
    let cancelled = false;
    let polls = 0;
    let timer: ReturnType<typeof setTimeout> | undefined;

    const poll = async () => {
      polls += 1;
      const result = await getGameYamlTest(gameId, ticket, contextOf(contextKey));
      if (cancelled) return;
      if (result.kind === "result" && (result.status === "passed" || result.status === "failed")) {
        setState({ kind: "done", status: result.status, error: result.error, world: result.world });
        return;
      }
      if (result.kind === "gone") {
        setState({ kind: "error", message: "Test introuvable ou expiré : relance-le." });
        return;
      }
      if (polls >= MAX_POLLS) {
        setState({ kind: "error", message: "Le test ne répond plus : relance-le." });
        return;
      }
      timer = setTimeout(() => void poll(), POLL_MS);
    };
    timer = setTimeout(() => void poll(), POLL_MS);

    return () => {
      cancelled = true;
      if (timer !== undefined) clearTimeout(timer);
    };
  }, [gameId, ticket, contextKey]);

  async function start(yaml: string) {
    if (gameId === undefined) return;
    setState({ kind: "starting" });
    const result = await startGameYamlTest(gameId, yaml);
    setState(result.ok ? { kind: "running", ticket: result.ticket } : { kind: "error", message: result.message });
  }

  const busy = state.kind === "starting" || state.kind === "running";

  return { state, busy, start, reset: () => setState({ kind: "idle" }) };
}

function contextOf(key: string): YamlTestContext | undefined {
  if (key.startsWith("e:")) return { eventId: key.slice(2) };
  if (key.startsWith("r:")) return { runId: key.slice(2) };
  return undefined;
}

export function GameYamlTestOutcome({ state }: { state: GameYamlTestState }) {
  switch (state.kind) {
    case "idle":
      return null;
    case "starting":
    case "running":
      return (
        <span className="inline-flex items-center gap-1.5 text-sm text-muted-foreground" role="status">
          <Loader2 aria-hidden className="size-4 animate-spin" /> Génération de test en cours, une à cinq minutes…
        </span>
      );
    case "error":
      return (
        <span className="text-sm text-danger" role="alert">
          {state.message}
        </span>
      );
    case "done":
      return state.status === "passed" ? (
        <span className="grid gap-1" role="status">
          <span className="inline-flex items-center gap-1.5 text-sm text-success">
            <CheckCircle2 aria-hidden className="size-4" /> Génération de test réussie : ce YAML fonctionne.
            {state.world ? <WorldSizeNote world={state.world} /> : null}
          </span>
          {state.world ? <WorldDurationNote world={state.world} /> : null}
        </span>
      ) : (
        <div className="grid w-full gap-2" role="alert">
          <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-danger">
            <XCircle aria-hidden className="size-4" /> La génération de test a échoué.
          </span>
          <GenerationFailureNotice audience="player" message={state.error ?? ""} />
        </div>
      );
  }
}

/** Story 56.1: how big the tested world is, so a player sees a giant world before an event. */
export function WorldSizeNote({ world }: { world: TestedWorldSize }) {
  const fmt = new Intl.NumberFormat("fr-FR");
  return (
    <span className="text-muted-foreground">
      {" "}Ton monde : {fmt.format(world.locations)} emplacements, {fmt.format(world.ownProgression)} objets de progression.
    </span>
  );
}

function formatHours(hours: number): string {
  return new Intl.NumberFormat("fr-FR", { maximumFractionDigits: hours < 10 ? 1 : 0 }).format(hours);
}

/**
 * Story 56.5: how long the world should take, against the target of the event or run when there is one. Past the
 * target it warns, it never blocks.
 */
export function WorldDurationNote({ world }: { world: TestedWorldSize }) {
  const hours = world.hours ?? null;
  const target = world.targetHours ?? null;
  if (hours === null && target === null) return null;
  const duration = hours === null ? "Durée inconnue pour ce jeu" : `Environ ${formatHours(hours)} h de jeu pour toi`;
  const line = target === null ? `${duration}.` : `${duration} ; la cible est de ${formatHours(target)} h.`;

  if (world.overTarget === true) {
    return (
      <span className="inline-flex items-start gap-1.5 text-sm text-[color:var(--color-warning)]">
        <TriangleAlert aria-hidden className="mt-0.5 size-4 shrink-0" />
        <span>
          {line} Ton monde risque de dépasser : un format plus court (moins d&apos;objectifs, moins d&apos;emplacements)
          laisse tout le monde finir ensemble.
        </span>
      </span>
    );
  }
  return <span className="text-sm text-muted-foreground">{line}</span>;
}
