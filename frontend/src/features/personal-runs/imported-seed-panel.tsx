"use client";

import { useRef, useState } from "react";
import { AlertCircle, FileUp, Info, Loader2, Users } from "lucide-react";

import { assignImportedSlot, importRunSeed } from "./personal-runs-api";
import type { ImportedSlot, PersonalRunParticipant } from "./types";

/**
 * Creating a party from a seed generated somewhere else (story 16.18).
 *
 * A seed can already exist - made on the Archipelago website, by a member locally, or by another
 * group - and regenerating it would give a different multiworld. Importing it means the archive
 * *is* the party: nobody declares a game, nobody writes a yaml.
 *
 * What it costs is the detailed progression, and the panel says so up front rather than letting the
 * player discover an empty tab later.
 */
export function ImportedSeedPanel({
  runId,
  importedSeed,
  importedSlots,
  participants,
  editable,
  onChanged,
}: {
  runId: string;
  importedSeed: boolean;
  importedSlots: ImportedSlot[];
  participants: PersonalRunParticipant[];
  /** The run is still a draft: a seed can be attached or replaced. */
  editable: boolean;
  onChanged: () => Promise<unknown> | void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [slots, setSlots] = useState<ImportedSlot[] | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);

  const shownSlots = slots ?? importedSlots;
  const nameOf = (userId: string): string =>
    participants.find((p) => p.userId === userId)?.displayName ?? "Joueur";

  async function upload(file: File) {
    setBusy(true);
    setError(null);
    const result = await importRunSeed(runId, file);
    if (result.ok) {
      setSlots(result.slots);
      await onChanged();
    } else {
      setError(result.message);
    }
    setBusy(false);
  }

  async function assign(slotId: string, userIds: string[]) {
    setBusy(true);
    setError(null);
    const result = await assignImportedSlot(runId, slotId, userIds);
    if (result.ok) {
      setSlots(result.slots);
      await onChanged();
    } else {
      setError(result.message);
    }
    setBusy(false);
  }

  return (
    <div className="grid gap-4 rounded-lg border border-border bg-surface p-4">
      <div className="grid gap-0.5">
        <p className="font-heading text-sm font-semibold text-foreground">Seed importée</p>
        <p className="text-xs text-muted-foreground">
          Héberge un multiworld déjà généré ailleurs, au lieu d&apos;en générer un ici.
        </p>
      </div>

      {editable && (
        <div className="grid gap-2">
          <input
            accept=".zip,.archipelago"
            className="sr-only"
            disabled={busy}
            onChange={(event) => {
              const file = event.target.files?.[0];
              event.target.value = "";
              if (file) void upload(file);
            }}
            ref={fileRef}
            type="file"
          />
          <button
            className="inline-flex min-h-10 w-fit items-center gap-2 rounded border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent hover:text-accent-text disabled:opacity-50"
            disabled={busy}
            onClick={() => fileRef.current?.click()}
            type="button"
          >
            {busy ? (
              <Loader2 aria-hidden className="size-4 animate-spin" />
            ) : (
              <FileUp aria-hidden className="size-4" />
            )}
            {importedSeed ? "Remplacer la seed" : "Importer une seed"}
          </button>
          <p className="text-xs text-muted-foreground">
            Une archive de sortie Archipelago (.zip) ou une multidata (.archipelago).
          </p>
        </div>
      )}

      {error !== null && (
        <p className="inline-flex items-start gap-2 text-xs text-[color:var(--color-danger)]">
          <AlertCircle aria-hidden className="mt-0.5 size-3.5 shrink-0" />
          {error}
        </p>
      )}

      {importedSeed && (
        <p className="flex items-start gap-2 rounded border border-border bg-background p-3 text-xs text-muted-foreground">
          <Info aria-hidden className="mt-0.5 size-3.5 shrink-0 text-accent-text" />
          <span>
            La progression détaillée (checks faisables, sphères, détail des objets) n&apos;est pas
            disponible sur une seed importée : la calculer demande les configurations des joueurs,
            que l&apos;archive ne contient pas. Tout le reste fonctionne.
          </span>
        </p>
      )}

      {shownSlots.length > 0 && (
        <ul className="grid gap-2">
          {shownSlots.map((slot) => (
            <li className="grid gap-1.5 rounded border border-border bg-background p-3" key={slot.slotId}>
              <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="text-sm font-semibold text-foreground">{slot.name}</p>
                <p className="text-xs text-muted-foreground">{slot.game}</p>
              </div>

              <p className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                <Users aria-hidden className="size-3.5" />
                {slot.assignedUserIds.length === 0
                  ? "Personne sur ce slot"
                  : slot.assignedUserIds.map(nameOf).join(", ")}
              </p>

              {editable && (
                <div className="flex flex-wrap gap-1.5">
                  {participants.map((participant) => {
                    const on = slot.assignedUserIds.includes(participant.userId);
                    return (
                      <button
                        aria-pressed={on}
                        className={[
                          "min-h-8 rounded-full border px-2.5 text-xs font-medium transition-colors disabled:opacity-50",
                          on
                            ? "border-accent bg-accent/10 text-accent-text"
                            : "border-border text-muted-foreground hover:border-accent",
                        ].join(" ")}
                        disabled={busy}
                        key={participant.userId}
                        onClick={() =>
                          void assign(
                            slot.slotId,
                            on
                              ? slot.assignedUserIds.filter((id) => id !== participant.userId)
                              : [...slot.assignedUserIds, participant.userId],
                          )
                        }
                        type="button"
                      >
                        {participant.displayName ?? "Joueur"}
                      </button>
                    );
                  })}
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
