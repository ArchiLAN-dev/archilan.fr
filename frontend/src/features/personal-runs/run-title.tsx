"use client";

import { useState } from "react";
import { Check, Pencil, X } from "lucide-react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

/** Same cap the API enforces on creation and rename (PersonalRunDrafts). */
const MAX_TITLE = 80;

type Props = {
  runId: string;
  title: string;
  canRename: boolean;
  onRenamed: () => void | Promise<void>;
};

/**
 * The run title, renamable in place by its owner (story 17.24).
 *
 * A run created from a game page is named "Partie {jeu}" automatically, so being stuck with that
 * name would be a poor outcome - and until this story no run could be renamed at all, whatever its
 * origin. Renaming stays allowed on a finished run: a title is a label, not configuration.
 */
export function RunTitle({ runId, title, canRename, onRenamed }: Props) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(title);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function open() {
    setDraft(title);
    setError(null);
    setEditing(true);
  }

  async function save() {
    const trimmed = draft.trim();
    if (trimmed === "") {
      setError("Le titre est requis.");
      return;
    }
    if (trimmed === title) {
      setEditing(false);
      return;
    }

    setSaving(true);
    setError(null);
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/runs/${runId}/title`, {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ title: trimmed }),
      });

      if (!res.ok) {
        const payload = (await res.json()) as { error?: { details?: { title?: string[] } } };
        setError(payload.error?.details?.title?.[0] ?? "Impossible de renommer la partie.");
        return;
      }

      setEditing(false);
      await onRenamed();
    } catch {
      setError("Erreur réseau.");
    } finally {
      setSaving(false);
    }
  }

  if (!editing) {
    return (
      <div className="flex items-center gap-2">
        <h1 className="font-heading text-3xl font-bold leading-tight text-foreground">{title}</h1>
        {canRename ? (
          <button
            aria-label="Renommer la partie"
            className="rounded p-1 text-muted-foreground transition-colors hover:text-foreground"
            onClick={open}
            title="Renommer la partie"
            type="button"
          >
            <Pencil aria-hidden className="size-4" />
          </button>
        ) : null}
      </div>
    );
  }

  return (
    <div className="grid gap-1">
      <div className="flex flex-wrap items-center gap-2">
        <input
          aria-label="Titre de la partie"
          autoFocus
          className="min-w-0 flex-1 rounded-lg border border-border bg-surface px-3 py-1.5 font-heading text-2xl font-bold text-foreground focus:outline-none focus:ring-1 focus:ring-accent-text/50"
          disabled={saving}
          maxLength={MAX_TITLE}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter") void save();
            if (e.key === "Escape") setEditing(false);
          }}
          value={draft}
        />
        <button
          aria-label="Enregistrer le titre"
          className="rounded p-1.5 text-accent-text transition-colors hover:bg-accent/10 disabled:opacity-50"
          disabled={saving}
          onClick={() => void save()}
          type="button"
        >
          <Check aria-hidden className="size-5" />
        </button>
        <button
          aria-label="Annuler"
          className="rounded p-1.5 text-muted-foreground transition-colors hover:text-foreground disabled:opacity-50"
          disabled={saving}
          onClick={() => setEditing(false)}
          type="button"
        >
          <X aria-hidden className="size-5" />
        </button>
      </div>
      {error !== null ? <p className="text-xs text-danger">{error}</p> : null}
    </div>
  );
}
