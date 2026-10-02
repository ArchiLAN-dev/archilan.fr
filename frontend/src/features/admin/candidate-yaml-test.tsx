"use client";

import { useEffect, useState } from "react";
import { CheckCircle2, FileCode2, Loader2, XCircle } from "lucide-react";

import { getApworldCandidateYamlTest, startApworldCandidateYamlTest } from "./admin-games-api";

const POLL_MS = 3000;
/** The orchestrator gives up on a test generation after a few minutes; stop polling well after that. */
const MAX_POLLS = 200;

export type YamlTestState =
  | { kind: "idle" }
  | { kind: "running"; jobId: string }
  | { kind: "done"; status: "passed" | "failed"; error: string | null }
  | { kind: "error"; message: string };

/**
 * Story 38.14: generate a pasted YAML against the candidate version - the admin's own, or the one of a player who
 * hit the problem - before putting it online. Nothing goes online, no incident is opened.
 */
export function CandidateYamlTest({ gameId }: { gameId: string }) {
  const [yaml, setYaml] = useState("");
  const [state, setState] = useState<YamlTestState>({ kind: "idle" });

  const jobId = state.kind === "running" ? state.jobId : null;

  useEffect(() => {
    if (jobId === null) return;
    let cancelled = false;
    let polls = 0;
    let timer: ReturnType<typeof setTimeout> | undefined;

    const poll = async () => {
      polls += 1;
      const result = await getApworldCandidateYamlTest(gameId, jobId);
      if (cancelled) return;
      if (result.kind === "result" && result.status !== "pending") {
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
  }, [gameId, jobId]);

  async function start() {
    const result = await startApworldCandidateYamlTest(gameId, yaml);
    setState(result.ok ? { kind: "running", jobId: result.jobId } : { kind: "error", message: result.message });
  }

  return (
    <details className="rounded border border-border bg-background">
      <summary className="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm font-semibold text-foreground">
        <FileCode2 aria-hidden className="size-4 text-accent" />
        Tester avec un YAML
      </summary>
      <div className="grid gap-2 border-t border-border p-3">
        <p className="text-xs text-muted-foreground">
          Colle un YAML (le tien, ou celui d&apos;un joueur qui a eu le problème) : une génération de test tourne avec cette
          nouvelle version, rien n&apos;est mis en ligne.
        </p>
        <textarea
          aria-label="YAML à tester"
          className="min-h-40 w-full rounded border border-border bg-surface p-2 font-mono text-xs text-foreground"
          onChange={(e) => setYaml(e.target.value)}
          placeholder={"name: Joueur\ngame: ...\n"}
          spellCheck={false}
          value={yaml}
        />
        <div className="flex flex-wrap items-center gap-3">
          <button
            className="inline-flex min-h-9 items-center gap-1.5 rounded border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-50"
            disabled={state.kind === "running" || yaml.trim() === ""}
            onClick={() => void start()}
            type="button"
          >
            Lancer le test
          </button>
          <YamlTestOutcome state={state} />
        </div>
      </div>
    </details>
  );
}

export function YamlTestOutcome({ state }: { state: YamlTestState }) {
  switch (state.kind) {
    case "idle":
      return null;
    case "running":
      return (
        <span className="inline-flex items-center gap-1.5 text-sm text-muted-foreground" role="status">
          <Loader2 aria-hidden className="size-4 animate-spin" /> Génération en cours, une à cinq minutes…
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
          <CheckCircle2 aria-hidden className="size-4" /> Génération réussie avec cette version.
        </span>
      ) : (
        <div className="grid w-full gap-1" role="alert">
          <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-danger">
            <XCircle aria-hidden className="size-4" /> Génération échouée avec cette version.
          </span>
          {state.error !== null && (
            <p className="rounded border border-border bg-surface px-3 py-2 font-mono text-xs text-foreground">{state.error}</p>
          )}
        </div>
      );
  }
}
