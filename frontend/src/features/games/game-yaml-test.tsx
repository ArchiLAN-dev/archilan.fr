"use client";

import { useEffect, useState } from "react";
import { CheckCircle2, Loader2, XCircle } from "lucide-react";

import { GenerationFailureNotice } from "@/features/games/generation-failure-notice";
import { getGameYamlTest, startGameYamlTest } from "./game-yaml-test-api";

const POLL_MS = 3000;
/** The orchestrator gives up on a test generation after a few minutes; stop polling well after that. */
const MAX_POLLS = 200;

export type GameYamlTestState =
  | { kind: "idle" }
  | { kind: "starting" }
  | { kind: "running"; ticket: string }
  | { kind: "done"; status: "passed" | "failed"; error: string | null }
  | { kind: "error"; message: string };

/**
 * Story 9.57: runs a test generation of a YAML and follows it until its verdict. `start` is an event handler;
 * the polling lives in an effect keyed by the ticket, so it stops when the editor unmounts.
 */
export function useGameYamlTest(gameId: string | undefined) {
  const [state, setState] = useState<GameYamlTestState>({ kind: "idle" });
  const ticket = state.kind === "running" ? state.ticket : null;

  useEffect(() => {
    if (ticket === null || gameId === undefined) return;
    let cancelled = false;
    let polls = 0;
    let timer: ReturnType<typeof setTimeout> | undefined;

    const poll = async () => {
      polls += 1;
      const result = await getGameYamlTest(gameId, ticket);
      if (cancelled) return;
      if (result.kind === "result" && (result.status === "passed" || result.status === "failed")) {
        setState({ kind: "done", status: result.status, error: result.error });
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
  }, [gameId, ticket]);

  async function start(yaml: string) {
    if (gameId === undefined) return;
    setState({ kind: "starting" });
    const result = await startGameYamlTest(gameId, yaml);
    setState(result.ok ? { kind: "running", ticket: result.ticket } : { kind: "error", message: result.message });
  }

  const busy = state.kind === "starting" || state.kind === "running";

  return { state, busy, start, reset: () => setState({ kind: "idle" }) };
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
        <span className="inline-flex items-center gap-1.5 text-sm text-success" role="status">
          <CheckCircle2 aria-hidden className="size-4" /> Génération de test réussie : ce YAML fonctionne.
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
