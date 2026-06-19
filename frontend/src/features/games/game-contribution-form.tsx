"use client";

import Link from "next/link";
import { useState } from "react";
import { PenLine } from "lucide-react";

import { useAuth } from "@/features/auth/auth-context";
import { submitContribution } from "./game-contribution-api";
import { InstallStepsEditor, type InstallStep } from "./install-steps-editor";

type Props =
  | { mode: "game"; gameSlug: string; initialSteps: InstallStep[] }
  | { mode: "proposed" };

/**
 * Community submission form (story 31.6): propose a tutorial change on an existing game (prefilled
 * with its current steps) or docs for a not-yet-listed game. Authenticated users only.
 */
export function GameContributionForm(props: Props) {
  const { user, loading } = useAuth();
  const [open, setOpen] = useState(false);
  const [steps, setSteps] = useState<InstallStep[]>(props.mode === "game" ? props.initialSteps : []);
  const [proposedName, setProposedName] = useState("");
  const [message, setMessage] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [feedback, setFeedback] = useState<{ tone: "ok" | "error"; text: string } | null>(null);

  const ctaLabel = props.mode === "game" ? "Proposer une modification" : "Proposer une doc (jeu non listé)";

  if (loading) {
    return null;
  }

  if (!user) {
    return (
      <p className="text-sm text-muted-foreground">
        <Link className="text-accent-text hover:underline" href="/connexion">
          Connecte-toi
        </Link>{" "}
        pour {props.mode === "game" ? "proposer une modification de ce tutoriel" : "proposer une doc pour un jeu non listé"}.
      </p>
    );
  }

  if (!open) {
    return (
      <div className="grid gap-2">
        <button
          className="inline-flex w-fit min-h-10 items-center gap-2 rounded border border-border px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
          onClick={() => {
            setOpen(true);
            setFeedback(null);
          }}
          type="button"
        >
          <PenLine aria-hidden="true" className="size-4" />
          {ctaLabel}
        </button>
        {feedback ? (
          <span className={`text-sm ${feedback.tone === "ok" ? "text-success" : "text-danger"}`}>{feedback.text}</span>
        ) : null}
      </div>
    );
  }

  async function submit() {
    setSubmitting(true);
    setFeedback(null);
    const ok = await submitContribution({
      gameSlug: props.mode === "game" ? props.gameSlug : undefined,
      proposedGameName: props.mode === "proposed" ? proposedName : undefined,
      steps,
      message: message.trim() === "" ? undefined : message,
    });
    if (ok) {
      setFeedback({ tone: "ok", text: "Merci ! Ta proposition a été envoyée pour modération." });
      setOpen(false);
      setMessage("");
      if (props.mode === "proposed") {
        setProposedName("");
        setSteps([]);
      }
    } else {
      setFeedback({ tone: "error", text: "Échec de l'envoi. Vérifie les étapes, les liens (http(s)) et le nom du jeu." });
    }
    setSubmitting(false);
  }

  return (
    <div className="grid gap-4 rounded-lg border border-border bg-surface p-5">
      <h3 className="font-heading text-lg font-semibold text-foreground">{ctaLabel}</h3>

      {props.mode === "proposed" ? (
        <label className="grid gap-1.5 text-sm">
          <span className="font-medium text-foreground">Nom du jeu</span>
          <input
            className="min-h-10 rounded border border-border bg-background px-3 text-sm outline-none focus:border-accent"
            onChange={(e) => setProposedName(e.target.value)}
            placeholder="Nom du jeu non encore listé"
            type="text"
            value={proposedName}
          />
        </label>
      ) : null}

      <InstallStepsEditor onChange={setSteps} steps={steps} />

      <label className="grid gap-1.5 text-sm">
        <span className="font-medium text-foreground">Message au modérateur (optionnel)</span>
        <textarea
          className="min-h-16 rounded border border-border bg-background px-3 py-2 text-sm outline-none focus:border-accent"
          onChange={(e) => setMessage(e.target.value)}
          value={message}
        />
      </label>

      <div className="flex flex-wrap items-center gap-3">
        <button
          className="inline-flex min-h-10 items-center justify-center rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
          disabled={submitting || (props.mode === "proposed" && proposedName.trim() === "")}
          onClick={submit}
          type="button"
        >
          {submitting ? "Envoi…" : "Envoyer la proposition"}
        </button>
        <button
          className="text-sm text-muted-foreground hover:text-foreground"
          onClick={() => setOpen(false)}
          type="button"
        >
          Annuler
        </button>
        {feedback ? (
          <span className={`text-sm ${feedback.tone === "ok" ? "text-success" : "text-danger"}`}>{feedback.text}</span>
        ) : null}
      </div>
    </div>
  );
}
