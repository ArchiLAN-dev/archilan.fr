"use client";

import { useState } from "react";
import { UserPlus, Users, X } from "lucide-react";

import { replaceSlotCoPlayers } from "./personal-runs-api";
import type { SlotCoPlayer } from "./types";

/**
 * Who else plays a slot (story 16.17).
 *
 * Archipelago has one slot per world, so a game played by three people is one slot; the platform
 * only knew the member who declared it, which meant no patch, no hints and no points for the
 * others. Naming them here is what makes them exist.
 *
 * Read-only for everyone but the run owner: they are the person who knows how the party is actually
 * organised, and letting players attach themselves to a slot would let anyone claim someone else's
 * game.
 */
export type CoPlayerCandidate = { userId: string; displayName: string };

export function SlotCoPlayers({
  runId,
  slotId,
  coPlayers,
  candidates,
  canManage,
  onChanged,
}: {
  runId: string;
  slotId: string;
  coPlayers: SlotCoPlayer[];
  /** Participants of the run who may be added - the slot's owner is not among them. */
  candidates: CoPlayerCandidate[];
  canManage: boolean;
  onChanged: () => Promise<unknown> | void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const current = coPlayers.map((coPlayer) => coPlayer.userId);
  const addable = candidates.filter((candidate) => !current.includes(candidate.userId));

  async function save(userIds: string[]) {
    setBusy(true);
    setError(null);
    const ok = await replaceSlotCoPlayers(runId, slotId, userIds);
    if (ok) {
      await onChanged();
    } else {
      setError("Modification impossible.");
    }
    setBusy(false);
  }

  if (!canManage && coPlayers.length === 0) return null;

  return (
    <div className="grid gap-2 border-t border-border pt-3">
      <p className="inline-flex items-center gap-1.5 text-xs font-semibold text-muted-foreground">
        <Users aria-hidden className="size-3.5" />
        {coPlayers.length === 0 ? "Joué en solo" : `Joué aussi par ${coPlayers.length} joueur${coPlayers.length > 1 ? "s" : ""}`}
      </p>

      {coPlayers.length > 0 && (
        <ul className="flex flex-wrap gap-1.5">
          {coPlayers.map((coPlayer) => (
            <li
              className="inline-flex items-center gap-1 rounded-full border border-border bg-background py-0.5 pl-2 pr-1 text-xs text-foreground"
              key={coPlayer.userId}
            >
              <span className="max-w-40 truncate">{coPlayer.displayName}</span>
              {canManage && (
                <button
                  aria-label={`Retirer ${coPlayer.displayName} de ce slot`}
                  className="rounded-full p-0.5 text-muted-foreground transition-colors hover:text-[color:var(--color-danger)] disabled:opacity-50"
                  disabled={busy}
                  onClick={() => void save(current.filter((id) => id !== coPlayer.userId))}
                  type="button"
                >
                  <X aria-hidden className="size-3" />
                </button>
              )}
            </li>
          ))}
        </ul>
      )}

      {canManage && addable.length > 0 && (
        <label className="inline-flex items-center gap-2 text-xs text-muted-foreground">
          <UserPlus aria-hidden className="size-3.5 shrink-0" />
          <span className="sr-only">Ajouter un co-joueur sur ce slot</span>
          <select
            className="min-h-9 flex-1 rounded border border-border bg-background px-2 text-xs text-foreground disabled:opacity-50"
            disabled={busy}
            onChange={(event) => {
              const userId = event.target.value;
              event.target.value = "";
              if (userId !== "") void save([...current, userId]);
            }}
            value=""
          >
            <option value="">Ajouter un co-joueur…</option>
            {addable.map((candidate) => (
              <option key={candidate.userId} value={candidate.userId}>
                {candidate.displayName}
              </option>
            ))}
          </select>
        </label>
      )}

      {error !== null && <p className="text-xs text-[color:var(--color-danger)]">{error}</p>}
    </div>
  );
}
