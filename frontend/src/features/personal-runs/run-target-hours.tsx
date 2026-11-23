"use client";

import { useState } from "react";

import { setRunTargetHours } from "./personal-runs-api";

type Props = {
  runId: string;
  targetHours: number | null;
  onSaved: () => void;
};

/**
 * Story 56.5: how long a world should last in a private party. The YAML test and the balance report warn a player
 * whose world goes past it; empty means no target.
 */
export function RunTargetHoursForm({ runId, targetHours, onSaved }: Props) {
  const [value, setValue] = useState(targetHours === null ? "" : String(targetHours));
  const [message, setMessage] = useState<{ kind: "ok" | "error"; text: string } | null>(null);
  const [busy, setBusy] = useState(false);

  async function save() {
    const hours = Number.parseInt(value.trim(), 10);
    setBusy(true);
    const error = await setRunTargetHours(runId, Number.isFinite(hours) && hours > 0 ? hours : null);
    setBusy(false);
    setMessage(error === null ? { kind: "ok", text: "Durée cible enregistrée." } : { kind: "error", text: error });
    if (error === null) onSaved();
  }

  return (
    <div className="grid gap-2">
      <h2 className="text-sm font-semibold text-foreground">Durée cible d&apos;un monde</h2>
      <p className="text-xs text-muted-foreground">
        Le test d&apos;un YAML et le rapport d&apos;équilibre préviennent un joueur dont le monde dépasse. Vide : pas de cible.
      </p>
      <div className="flex flex-wrap items-center gap-2">
        <label className="flex items-center gap-2 text-sm text-foreground">
          <input
            className="w-24 rounded border border-border bg-background px-3 py-2 text-sm text-foreground"
            inputMode="numeric"
            max={2000}
            min={1}
            onChange={(event) => setValue(event.target.value)}
            type="number"
            value={value}
          />
          heures
        </label>
        <button
          className="inline-flex min-h-9 items-center rounded-md border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:opacity-50"
          disabled={busy}
          onClick={() => void save()}
          type="button"
        >
          Enregistrer
        </button>
      </div>
      {message !== null ? (
        <p className={message.kind === "ok" ? "text-xs text-success" : "text-xs text-danger"} role="status">
          {message.text}
        </p>
      ) : null}
    </div>
  );
}
