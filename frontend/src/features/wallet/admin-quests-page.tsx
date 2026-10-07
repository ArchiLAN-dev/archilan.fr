"use client";

import { useRef, useState } from "react";
import { CosmeticRewardPicker, type CosmeticReward } from "@/features/community/cosmetic-reward-picker";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus, Trash2 } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { Dialog, DialogBody, DialogFooter } from "@/components/ui/dialog";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import {
  QUEST_LIMITS,
  editQuest,
  fetchAdminQuests,
  objectivesSummary,
  setQuestRetired,
  writeQuest,
  type AdminQuest,
  type QuestMetricOption,
  type QuestObjectiveTerms,
  type QuestScopes,
  type QuestStats,
  type QuestTerms,
} from "./admin-quests-api";
import { AdminQuestWeeksView } from "./admin-quest-weeks";
import { StatusLine, fieldClass, useQuestChange, type ViewProps } from "./admin-quests-shared";
import { PelleAmount } from "./pelle-amount";

const QUERY_KEY = ["admin-quests"] as const;

type QuestsView = "weeks" | "types";

// The weeks tab explains the draw in its own panel.
const INTROS: Record<QuestsView, string | null> = {
  weeks: null,
  types: "Les quêtes que les semaines peuvent servir : leurs objectifs, leur récompense, et si elles sortent au tirage ou seulement épinglées.",
};

/**
 * One tab of the weekly quests' page (story 41.15), under the shared header and tabs of its layout:
 * `/admin/quetes/semaines` plans the current and coming weeks, `/admin/quetes/types` writes the quests they serve.
 */
export function AdminQuestsPage({ view }: { view: QuestsView }) {
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: QUERY_KEY, queryFn: fetchAdminQuests, staleTime: DEFAULT_STALE_TIME, retry: false });

  async function after(error: string | null): Promise<string | null> {
    await queryClient.invalidateQueries({ queryKey: QUERY_KEY });
    await queryClient.invalidateQueries({ queryKey: ["my-quests"] });
    return error;
  }

  return (
    <div className="grid gap-4">
      {INTROS[view] ? <p className="max-w-3xl text-sm text-muted-foreground">{INTROS[view]}</p> : null}
      {isLoading ? <p className="text-sm text-muted-foreground">Chargement…</p> : null}
      {!isLoading && !data ? <p className="text-sm text-danger">Impossible de charger les quêtes.</p> : null}
      {data ? view === "weeks" ? <AdminQuestWeeksView data={data} onChange={after} /> : <AdminQuestTypesView data={data} onChange={after} /> : null}
    </div>
  );
}

/** `/admin/quetes/types`: every quest, written, edited, retired or restored. */
export function AdminQuestTypesView({ data, onChange }: ViewProps) {
  const { message, pending, apply } = useQuestChange(onChange);
  const [editing, setEditing] = useState<AdminQuest | "new" | null>(null);

  return (
    <div className="grid gap-3">
      <StatusLine message={message} />
      <div className="flex justify-end">
        <button className={buttonVariants({ variant: "primary" })} onClick={() => setEditing("new")} type="button">
          <Plus aria-hidden className="size-4" />
          Nouvelle quête
        </button>
      </div>
      {data.quests.length === 0 ? (
        <p className="rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">Aucune quête pour l&apos;instant.</p>
      ) : (
        <ul className="divide-y divide-border overflow-hidden rounded-lg border border-border bg-surface" role="list">
          {data.quests.map((quest) => (
            <li className="flex flex-wrap items-center gap-3 px-4 py-3" key={quest.id}>
              <div className="grid min-w-0 flex-1 gap-0.5">
                <p className={`flex flex-wrap items-center gap-2 font-semibold ${quest.retired ? "text-muted-foreground" : "text-foreground"}`}>
                  {quest.title}
                  <span className={`rounded-full border px-2 py-0.5 text-xs font-medium ${quest.inDraw ? "border-accent/40 text-accent-text" : "border-border text-muted-foreground"}`}>
                    {quest.inDraw ? "Dans le tirage" : "Hors tirage"}
                  </span>
                  {quest.inDraw && quest.drawWeight > 1 ? (
                    <span className="rounded-full border border-accent/40 px-2 py-0.5 text-xs font-medium text-accent-text">×{quest.drawWeight}</span>
                  ) : null}
                  {quest.retired ? <span className="rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground">Retirée</span> : null}
                </p>
                {quest.description !== "" ? <p className="text-xs text-muted-foreground">{quest.description}</p> : null}
                <p className="text-xs text-muted-foreground">{objectivesSummary(quest.objectives, data.metrics, data.scopes)}</p>
                <p className="text-xs text-muted-foreground">{questStatsLine(quest.stats)}</p>
              </div>
              <PelleAmount amount={quest.reward} className="text-xs font-semibold text-warning" signed />
              <div className="flex gap-1">
                <button className={buttonVariants({ variant: "ghost" })} onClick={() => setEditing(quest)} type="button">
                  Modifier
                </button>
                <button
                  className={buttonVariants({ variant: "ghost" })}
                  disabled={pending}
                  onClick={() => void apply(() => setQuestRetired(quest.id, !quest.retired), quest.retired ? "Quête rétablie." : "Quête retirée.")}
                  type="button"
                >
                  {quest.retired ? "Rétablir" : "Retirer"}
                </button>
              </div>
            </li>
          ))}
        </ul>
      )}

      {editing !== null ? (
        <QuestDialog
          metrics={data.metrics}
          scopes={data.scopes}
          onClose={() => setEditing(null)}
          onSave={async (terms) => {
            const done = await apply(
              () => (editing === "new" ? writeQuest(terms) : editQuest(editing.id, terms)),
              editing === "new" ? "Quête créée." : "Quête modifiée.",
            );
            if (done) setEditing(null);
          }}
          quest={editing === "new" ? null : editing}
        />
      ) : null}
    </div>
  );
}

/** Writing or editing a quest: its text, reward, draw and weight, and 1 to 5 objectives taken from the catalog. */
export function QuestDialog({
  quest,
  metrics,
  scopes,
  onClose,
  onSave,
}: {
  quest: AdminQuest | null;
  metrics: QuestMetricOption[];
  scopes: QuestScopes;
  onClose: () => void;
  onSave: (terms: QuestTerms) => Promise<void>;
}) {
  const [title, setTitle] = useState(quest?.title ?? "");
  const [description, setDescription] = useState(quest?.description ?? "");
  const [reward, setReward] = useState(String(quest?.reward ?? 30));
  const [inDraw, setInDraw] = useState(quest?.inDraw ?? true);
  const [drawWeight, setDrawWeight] = useState(quest?.drawWeight ?? 1);
  // Story 41.28: the cosmetic it unlocks the first time.
  const [cosmetic, setCosmetic] = useState<CosmeticReward | null>(quest?.cosmetic ? { type: quest.cosmetic.type, key: quest.cosmetic.key } : null);
  // Each row keeps its own id: a type may come back aimed at another game, so the type is no key.
  const [rows, setRows] = useState<ObjectiveRow[]>(() =>
    (quest?.objectives ?? [{ metric: metrics[0]?.key ?? "goals", target: 1 }]).map((objective, index) => ({ ...objective, rowId: index })),
  );
  const nextRowId = useRef(rows.length);
  const [pending, setPending] = useState(false);

  const objectives: QuestObjectiveTerms[] = rows.map((row) => ({
    metric: row.metric,
    target: row.target,
    ...(row.scope && row.scopeId ? { scope: row.scope, scopeId: row.scopeId } : {}),
  }));
  const parsedReward = Number.parseInt(reward, 10);
  const terms: QuestTerms = { title: title.trim(), description: description.trim(), reward: parsedReward, objectives, inDraw, drawWeight, cosmetic: cosmetic?.key ? cosmetic : null };
  const error = questTermsError(terms);
  const hasScopes = scopes.games.length > 0 || scopes.events.length > 0;

  function update(rowId: number, next: Partial<QuestObjectiveTerms>): void {
    setRows(rows.map((row) => (row.rowId === rowId ? { ...row, ...next } : row)));
  }

  function addRow(): void {
    const unused = metrics.find((metric) => !rows.some((row) => row.metric === metric.key && !row.scope)) ?? metrics[0];
    setRows([...rows, { metric: unused?.key ?? "goals", target: 1, rowId: nextRowId.current++ }]);
  }

  return (
    <Dialog
      description="Tous les objectifs sont à remplir dans la semaine ; chacun a sa barre de progression chez les membres."
      onOpenChange={(open) => {
        if (!open) onClose();
      }}
      open
      size="wide"
      title={quest ? `Modifier « ${quest.title} »` : "Nouvelle quête"}
    >
      <form
        className="flex min-h-0 flex-1 flex-col"
        onSubmit={async (event) => {
          event.preventDefault();
          if (error !== null) return;
          setPending(true);
          await onSave(terms);
          setPending(false);
        }}
      >
        <DialogBody className="grid gap-3">
          <label className="grid gap-1 text-sm">
            <span className="font-medium text-foreground">Titre</span>
            <input className={fieldClass} maxLength={QUEST_LIMITS.maxTitle} onChange={(e) => setTitle(e.target.value)} value={title} />
          </label>
          <label className="grid gap-1 text-sm">
            <span className="font-medium text-foreground">Description (facultative)</span>
            <input className={fieldClass} maxLength={QUEST_LIMITS.maxDescription} onChange={(e) => setDescription(e.target.value)} value={description} />
          </label>
          <label className="grid gap-1 text-sm">
            <span className="font-medium text-foreground">Récompense (pelles en or)</span>
            <input
              className={`${fieldClass} w-32`}
              inputMode="numeric"
              max={QUEST_LIMITS.maxReward}
              min={QUEST_LIMITS.minReward}
              onChange={(e) => setReward(e.target.value)}
              type="number"
              value={reward}
            />
          </label>

          <fieldset className="grid gap-2">
            <legend className="mb-1 text-sm font-medium text-foreground">Objectifs</legend>
            {rows.map((row, index) => {
              const metric = metrics.find((candidate) => candidate.key === row.metric);
              return (
                <div className="flex flex-wrap items-center gap-2" key={row.rowId}>
                  <select
                    aria-label={`Type de l'objectif ${index + 1}`}
                    className={`${fieldClass} min-w-40 flex-1`}
                    onChange={(e) => {
                      const next = metrics.find((candidate) => candidate.key === e.target.value);
                      // A type that cannot aim at a game or an event drops the target it had.
                      update(row.rowId, next?.scopable ? { metric: e.target.value } : { metric: e.target.value, scope: undefined, scopeId: undefined });
                    }}
                    value={row.metric}
                  >
                    {metrics.map((candidate) => (
                      <option key={candidate.key} value={candidate.key}>
                        {candidate.label}
                      </option>
                    ))}
                  </select>
                  <input
                    aria-label={`Cible de l'objectif ${index + 1}`}
                    className={`${fieldClass} w-24`}
                    inputMode="numeric"
                    max={QUEST_LIMITS.maxTarget}
                    min={QUEST_LIMITS.minTarget}
                    onChange={(e) => update(row.rowId, { target: Number.parseInt(e.target.value, 10) || 0 })}
                    type="number"
                    value={row.target === 0 ? "" : row.target}
                  />
                  {metric?.scopable && hasScopes ? (
                    <select
                      aria-label={`Où compte l'objectif ${index + 1}`}
                      className={`${fieldClass} min-w-40 flex-1`}
                      onChange={(e) => {
                        const [scope, scopeId] = e.target.value.split(":");
                        update(row.rowId, scope === "game" || scope === "event" ? { scope, scopeId } : { scope: undefined, scopeId: undefined });
                      }}
                      value={row.scope && row.scopeId ? `${row.scope}:${row.scopeId}` : ""}
                    >
                      <option value="">Partout</option>
                      {scopes.games.length > 0 ? (
                        <optgroup label="Sur un jeu">
                          {scopes.games.map((game) => (
                            <option key={game.id} value={`game:${game.id}`}>
                              {game.name}
                            </option>
                          ))}
                        </optgroup>
                      ) : null}
                      {scopes.events.length > 0 ? (
                        <optgroup label="Pendant un événement">
                          {scopes.events.map((event) => (
                            <option key={event.id} value={`event:${event.id}`}>
                              {event.title}
                            </option>
                          ))}
                        </optgroup>
                      ) : null}
                    </select>
                  ) : null}
                  <button
                    aria-label={`Supprimer l'objectif ${index + 1}`}
                    className={buttonVariants({ variant: "ghost" })}
                    disabled={rows.length === 1}
                    onClick={() => setRows(rows.filter((candidate) => candidate.rowId !== row.rowId))}
                    type="button"
                  >
                    <Trash2 aria-hidden className="size-4" />
                  </button>
                </div>
              );
            })}
            {rows.length < QUEST_LIMITS.maxObjectives ? (
              <button className={`${buttonVariants({ variant: "ghost" })} justify-self-start`} onClick={addRow} type="button">
                <Plus aria-hidden className="size-4" />
                Ajouter un objectif
              </button>
            ) : null}
            <p className="text-xs text-muted-foreground">Les goals, les checks et les parties peuvent compter sur un jeu ou pendant un événement précis (hors hebdos).</p>
          </fieldset>

          <div className="grid gap-3 sm:grid-cols-2">
            <label className="flex items-start gap-2 text-sm">
              <input checked={inDraw} className="mt-1" onChange={(e) => setInDraw(e.target.checked)} type="checkbox" />
              <span>
                <span className="font-medium text-foreground">Dans le tirage</span>
                <span className="block text-xs text-muted-foreground">Décochée, la quête ne sort que si on l&apos;épingle à une semaine.</span>
              </span>
            </label>
            <label className="grid gap-1 text-sm">
              <span className="font-medium text-foreground">Poids au tirage</span>
              <select className={`${fieldClass} w-40`} disabled={!inDraw} onChange={(e) => setDrawWeight(Number.parseInt(e.target.value, 10))} value={drawWeight}>
                {[1, 2, 3, 4, 5].map((weight) => (
                  <option key={weight} value={weight}>
                    {weight === 1 ? "Normal (×1)" : `×${weight} plus souvent`}
                  </option>
                ))}
              </select>
            </label>
          </div>
          <CosmeticRewardPicker id="quest-cosmetic" onChange={setCosmetic} value={cosmetic} />
          {error !== null && title !== "" ? <p className="text-sm text-danger">{error}</p> : null}
        </DialogBody>
        <DialogFooter>
          <button className={buttonVariants({ variant: "ghost" })} onClick={onClose} type="button">
            Annuler
          </button>
          <button className={buttonVariants({ variant: "primary" })} disabled={error !== null || pending} type="submit">
            {quest ? "Enregistrer" : "Créer la quête"}
          </button>
        </DialogFooter>
      </form>
    </Dialog>
  );
}

type ObjectiveRow = QuestObjectiveTerms & { rowId: number };

/** What the API would refuse, said before sending; null when the quest can be saved. */
export function questTermsError(terms: QuestTerms): string | null {
  if (terms.title === "" || terms.title.length > QUEST_LIMITS.maxTitle) return `Un titre de 1 à ${QUEST_LIMITS.maxTitle} caractères.`;
  if (!Number.isInteger(terms.reward) || terms.reward < QUEST_LIMITS.minReward || terms.reward > QUEST_LIMITS.maxReward) {
    return `Une récompense de ${QUEST_LIMITS.minReward} à ${QUEST_LIMITS.maxReward} pelles.`;
  }
  if (terms.objectives.length === 0) return "Au moins un objectif.";
  if (terms.objectives.some((objective) => !Number.isInteger(objective.target) || objective.target < QUEST_LIMITS.minTarget || objective.target > QUEST_LIMITS.maxTarget)) {
    return `Une cible de ${QUEST_LIMITS.minTarget} à ${QUEST_LIMITS.maxTarget}.`;
  }
  // Story 41.18: a type may come back, aimed elsewhere - never twice at the same place.
  const keys = terms.objectives.map((objective) => `${objective.metric}@${objective.scope ?? ""}:${objective.scopeId ?? ""}`);
  if (new Set(keys).size !== keys.length) return "Deux objectifs identiques : change le type ou ce qu'il vise.";
  return null;
}

/**
 * Story 41.16: how a quest did, in one line - « Servie 3 semaines · 12 membres l'ont réussie la dernière fois ·
 * 480 pelles versées ». A quest never served says so.
 */
export function questStatsLine(stats: QuestStats): string {
  if (stats.weeksServed === 0) return "Jamais servie pour l'instant.";
  const served = `Servie ${stats.weeksServed} semaine${stats.weeksServed > 1 ? "s" : ""}`;
  const last =
    stats.lastWeek === null
      ? "première semaine en cours"
      : `${stats.lastMembers} membre${stats.lastMembers > 1 ? "s" : ""} l'${stats.lastMembers > 1 ? "ont" : "a"} réussie la dernière fois`;
  return `${served} · ${last} · ${stats.pelles} pelle${stats.pelles > 1 ? "s" : ""} versée${stats.pelles > 1 ? "s" : ""}`;
}
