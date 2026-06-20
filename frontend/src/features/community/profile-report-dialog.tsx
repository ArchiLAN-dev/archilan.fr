"use client";

import { useState } from "react";
import { AlertCircle, CheckCircle, Loader2, X } from "lucide-react";

import { REPORT_CATEGORIES, REPORT_PROBLEMS, reportProfile, type ReportResult } from "./community-report-api";

const MAX_COMMENT = 500;

type State = { kind: "form" } | { kind: "sending" } | { kind: "sent" } | { kind: "error"; message: string };

const ERROR_MESSAGES: Record<Exclude<ReportResult, "ok">, string> = {
  forbidden: "Vous ne pouvez pas signaler votre propre profil.",
  invalid: "Sélectionnez un type et un contenu valides.",
  not_found: "Ce joueur est introuvable.",
  error: "Le signalement n'a pas pu être envoyé. Réessayez.",
};

export function ProfileReportDialog({ slug, name, onClose }: { slug: string; name: string; onClose: () => void }) {
  const [category, setCategory] = useState<string>(REPORT_CATEGORIES[0].value);
  const [problem, setProblem] = useState<string>(REPORT_PROBLEMS[0].value);
  const [comment, setComment] = useState("");
  const [state, setState] = useState<State>({ kind: "form" });

  async function submit() {
    setState({ kind: "sending" });
    const result = await reportProfile(slug, { category, problem, comment });
    if (result === "ok") {
      setState({ kind: "sent" });
    } else {
      setState({ kind: "error", message: ERROR_MESSAGES[result] });
    }
  }

  return (
    <div
      aria-labelledby="report-title"
      aria-modal="true"
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      onClick={onClose}
      role="dialog"
    >
      <div className="w-full max-w-md rounded-xl border border-border bg-surface p-5 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <div className="mb-4 flex items-start justify-between gap-3">
          <h2 className="font-heading text-lg font-bold text-foreground" id="report-title">
            Signaler {name}
          </h2>
          <button aria-label="Fermer" className="text-muted-foreground hover:text-foreground" onClick={onClose} type="button">
            <X aria-hidden className="size-5" />
          </button>
        </div>

        {state.kind === "sent" ? (
          <div className="grid gap-4">
            <p className="flex items-center gap-2 text-sm text-foreground">
              <CheckCircle aria-hidden className="size-5 text-emerald-500" /> Merci, le signalement a bien été transmis à la modération.
            </p>
            <button className="ml-auto inline-flex min-h-9 items-center rounded-lg bg-accent px-4 text-sm font-semibold text-white hover:bg-accent-hover" onClick={onClose} type="button">
              Fermer
            </button>
          </div>
        ) : (
          <div className="grid gap-4">
            <label className="grid gap-1 text-sm">
              <span className="font-medium text-foreground">Type de signalement</span>
              <select className="min-h-9 rounded-lg border border-border bg-background px-2 text-sm text-foreground focus:border-accent focus:outline-none" onChange={(e) => setCategory(e.target.value)} value={category}>
                {REPORT_CATEGORIES.map((c) => (
                  <option key={c.value} value={c.value}>
                    {c.label}
                  </option>
                ))}
              </select>
            </label>

            <label className="grid gap-1 text-sm">
              <span className="font-medium text-foreground">Contenu problématique</span>
              <select className="min-h-9 rounded-lg border border-border bg-background px-2 text-sm text-foreground focus:border-accent focus:outline-none" onChange={(e) => setProblem(e.target.value)} value={problem}>
                {REPORT_PROBLEMS.map((p) => (
                  <option key={p.value} value={p.value}>
                    {p.label}
                  </option>
                ))}
              </select>
            </label>

            <label className="grid gap-1 text-sm">
              <span className="font-medium text-foreground">Commentaire (optionnel)</span>
              <textarea
                className="min-h-20 rounded-lg border border-border bg-background px-2 py-1.5 text-sm text-foreground focus:border-accent focus:outline-none"
                maxLength={MAX_COMMENT}
                onChange={(e) => setComment(e.target.value)}
                placeholder="Précisez si besoin…"
                value={comment}
              />
            </label>

            {state.kind === "error" ? (
              <p className="flex items-center gap-1.5 text-xs text-destructive">
                <AlertCircle aria-hidden className="size-3.5" /> {state.message}
              </p>
            ) : null}

            <div className="flex justify-end gap-2">
              <button className="inline-flex min-h-9 items-center rounded-lg border border-border px-4 text-sm font-medium text-muted-foreground hover:text-foreground" onClick={onClose} type="button">
                Annuler
              </button>
              <button
                className="inline-flex min-h-9 items-center gap-1.5 rounded-lg bg-accent px-4 text-sm font-semibold text-white hover:bg-accent-hover disabled:opacity-50"
                disabled={state.kind === "sending"}
                onClick={() => void submit()}
                type="button"
              >
                {state.kind === "sending" ? <Loader2 aria-hidden className="size-4 animate-spin" /> : null}
                Envoyer le signalement
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
