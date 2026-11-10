"use client";

import { useQuery } from "@tanstack/react-query";
import { Plus, Trash2, X } from "lucide-react";
import { useState } from "react";

import { fetchGameSelection, fetchSlotYamlData, type AvailableGame } from "@/features/events/events-api";
import { YamlOptionEditor } from "@/features/events/yaml-option-editor";

import {
  adminGameSelectionUrl,
  adminSlotYamlUrl,
  saveAdminRegistrationGames,
} from "./admin-registration-api";
import { AdminWarningsDialog, useAcknowledgedAction } from "./admin-warnings";

/** A slot in the admin's draft: an existing slot keeps its id (and its YAML), a new one gets a local key. */
export type DraftSlot = { key: string; gameId: string; gameName: string };

/** The games an admin can add: every game of the event that is not switched off. */
export function addableGames(games: AvailableGame[]): AvailableGame[] {
  return games.filter((game) => game.disabled !== true);
}

/** Story 5.10: the draft already holds the event's cap (none means no cap); an admin may go past it, with a warning. */
export function isAtCap(draft: DraftSlot[], max: number | null): boolean {
  return max !== null && draft.length >= max;
}

type GamesEditorProps = {
  eventId: string;
  registrationId: string;
  slots: { slotId: string; gameId: string; gameName: string }[];
  onClose: () => void;
  onSaved: () => void;
};

/** Story 5.9: the admin adds or removes the registration's games on the player's behalf. */
export function AdminGamesEditor({ eventId, registrationId, slots, onClose, onSaved }: GamesEditorProps) {
  const [draft, setDraft] = useState<DraftSlot[]>(() =>
    slots.map((slot) => ({ key: slot.slotId, gameId: slot.gameId, gameName: slot.gameName })),
  );
  const [picked, setPicked] = useState("");
  const saving = useAcknowledgedAction(
    (acknowledgeWarnings) => saveAdminRegistrationGames(eventId, registrationId, draft.map((slot) => slot.gameId), acknowledgeWarnings),
    () => { onSaved(); },
  );

  const selectionQuery = useQuery({
    queryKey: ["admin-registration-selection", eventId, registrationId],
    queryFn: async () => {
      const result = await fetchGameSelection(registrationId, adminGameSelectionUrl(eventId, registrationId));
      if (result.kind !== "success") throw new Error(result.kind === "error" ? result.message : "Inscription introuvable.");
      return result.data;
    },
    staleTime: 30_000,
    retry: false,
  });

  const selection = selectionQuery.data;
  const games = selection ? addableGames(selection.availableGames) : [];
  const max = selection?.maxGamesPerRegistrant ?? null;

  function add() {
    const game = games.find((candidate) => candidate.id === picked);
    if (!game) return;
    setDraft((current) => [...current, { key: `new-${game.id}-${Date.now()}`, gameId: game.id, gameName: game.name }]);
    setPicked("");
  }


  return (
    <section aria-label="Modifier les jeux" className="grid gap-4 border border-accent/40 bg-surface p-5">
      <div className="flex items-center justify-between gap-3">
        <h2 className="font-heading text-xl font-semibold text-foreground">Modifier les jeux</h2>
        <button
          aria-label="Fermer"
          className="inline-flex min-h-9 items-center rounded border border-border px-2 text-muted-foreground hover:text-foreground"
          onClick={onClose}
          type="button"
        >
          <X aria-hidden="true" className="size-4" />
        </button>
      </div>
      <p className="text-sm text-muted-foreground">
        Les jeux conservés gardent leur YAML ; un jeu ajouté part de son YAML par défaut. Possible même inscriptions closes.
      </p>

      {draft.length === 0 ? (
        <p className="text-sm text-muted-foreground">Aucun jeu.</p>
      ) : (
        <ul className="grid gap-2">
          {draft.map((slot, index) => (
            <li className="flex items-center gap-3 border border-border bg-background px-3 py-2" key={slot.key}>
              <span className="text-xs text-muted-foreground">{index + 1}.</span>
              <span className="text-sm text-foreground">{slot.gameName}</span>
              <button
                aria-label={`Retirer ${slot.gameName}`}
                className="ml-auto inline-flex min-h-9 items-center rounded border border-border px-2 text-danger hover:border-danger"
                onClick={() => setDraft((current) => current.filter((other) => other.key !== slot.key))}
                type="button"
              >
                <Trash2 aria-hidden="true" className="size-4" />
              </button>
            </li>
          ))}
        </ul>
      )}

      {selectionQuery.isError ? (
        <p className="text-sm text-danger" role="alert">{selectionQuery.error.message}</p>
      ) : null}

      {selection ? (
        <div className="grid gap-2">
          <div className="flex flex-col gap-2 sm:flex-row">
            <label className="sr-only" htmlFor="admin-add-game">Jeu à ajouter</label>
            <select
              className="min-h-11 flex-1 rounded border border-border bg-background px-3 text-sm text-foreground"
              id="admin-add-game"
              onChange={(event) => setPicked(event.target.value)}
              value={picked}
            >
              <option value="">Choisir un jeu à ajouter</option>
              {games.map((game) => (
                <option key={game.id} value={game.id}>{game.name}</option>
              ))}
            </select>
            <button
              className="inline-flex min-h-11 items-center justify-center gap-1.5 rounded border border-border px-3 text-sm text-foreground hover:border-accent disabled:opacity-50"
              disabled={picked === ""}
              onClick={add}
              type="button"
            >
              <Plus aria-hidden="true" className="size-4" />
              Ajouter
            </button>
          </div>
          {isAtCap(draft, max) ? (
            <p className="text-sm text-accent-warm">
              Maximum joueur atteint ({max} jeu(x) par inscription) : au-delà, un avertissement te sera demandé.
            </p>
          ) : null}
        </div>
      ) : null}

      {saving.error ? <p className="text-sm text-danger" role="alert">{saving.error}</p> : null}
      {saving.warnings ? (
        <AdminWarningsDialog
          busy={saving.busy}
          confirmLabel="Enregistrer quand même"
          onCancel={saving.dismiss}
          onConfirm={saving.confirm}
          warnings={saving.warnings}
        />
      ) : null}

      <div className="flex flex-col gap-2 sm:flex-row">
        <button
          className="inline-flex min-h-11 items-center justify-center rounded border border-accent bg-accent px-4 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-60"
          disabled={saving.busy}
          onClick={saving.run}
          type="button"
        >
          {saving.busy ? "Enregistrement..." : "Enregistrer les jeux"}
        </button>
        <button
          className="inline-flex min-h-11 items-center justify-center rounded border border-border px-4 text-sm text-foreground"
          onClick={onClose}
          type="button"
        >
          Annuler
        </button>
      </div>
    </section>
  );
}

type SlotYamlEditorProps = {
  eventId: string;
  registrationId: string;
  slotId: string;
  onClose: () => void;
  onSaved: () => void;
};

/** Story 5.9: the player's YAML editor, opened by an admin on one slot and saved through the admin route. */
export function AdminSlotYamlEditor({ eventId, registrationId, slotId, onClose, onSaved }: SlotYamlEditorProps) {
  const slotQuery = useQuery({
    queryKey: ["admin-registration-slot-yaml", eventId, registrationId, slotId],
    queryFn: async () => {
      const result = await fetchSlotYamlData(registrationId, slotId, adminGameSelectionUrl(eventId, registrationId));
      if (result.kind !== "success") throw new Error(result.kind === "error" ? result.message : "Slot introuvable.");
      return result.data;
    },
    staleTime: 0,
    // A reopened editor must start from the saved YAML: YamlOptionEditor reads it once, on mount.
    gcTime: 0,
    retry: false,
  });

  return (
    <div className="grid gap-3 border-t border-border px-5 py-4">
      <div className="flex items-center justify-between gap-3">
        <p className="text-sm font-semibold text-foreground">Modifier le YAML (admin)</p>
        <button
          className="inline-flex min-h-9 items-center rounded border border-border px-3 text-xs text-muted-foreground hover:text-foreground"
          onClick={onClose}
          type="button"
        >
          Fermer
        </button>
      </div>
      {slotQuery.isPending ? <p className="text-sm text-muted-foreground">Chargement...</p> : null}
      {slotQuery.isError ? <p className="text-sm text-danger" role="alert">{slotQuery.error.message}</p> : null}
      {slotQuery.data ? (
        <YamlOptionEditor
          defaultYaml={slotQuery.data.game.defaultYaml}
          gameId={slotQuery.data.game.id}
          itemGroups={slotQuery.data.game.itemGroups}
          itemNames={slotQuery.data.game.itemNames}
          locationNames={slotQuery.data.game.locationNames}
          optionTypes={slotQuery.data.game.optionTypes}
          playerYaml={slotQuery.data.slot.playerYaml}
          registrationId={registrationId}
          registrationOpen
          saveUrl={adminSlotYamlUrl(eventId, registrationId, slotId)}
          slotId={slotId}
          onSaved={onSaved}
        />
      ) : null}
    </div>
  );
}
