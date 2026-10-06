"use client";

import { useState } from "react";
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
                  {quest.retired ? <span className="rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground">Retirée</span> : null}
                </p>
                {quest.description !== "" ? <p className="text-xs text-muted-foreground">{quest.description}</p> : null}
                <p className="text-xs text-muted-foreground">{objectivesSummary(quest.objectives, data.metrics)}</p>
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

/** Writing or editing a quest: its text, reward, draw, and 1 to 5 objectives taken from the catalog. */
export function QuestDialog({
  quest,
  metrics,
  onClose,
  onSave,
}: {
  quest: AdminQuest | null;
  metrics: QuestMetricOption[];
  onClose: () => void;
  onSave: (terms: QuestTerms) => Promise<void>;
}) {
  const [title, setTitle] = useState(quest?.title ?? "");
  const [description, setDescription] = useState(quest?.description ?? "");
  const [reward, setReward] = useState(String(quest?.reward ?? 30));
  const [inDraw, setInDraw] = useState(quest?.inDraw ?? true);
  const [objectives, setObjectives] = useState<QuestObjectiveTerms[]>(quest?.objectives ?? [{ metric: metrics[0]?.key ?? "goals", target: 1 }]);
  const [pending, setPending] = useState(false);

  const parsedReward = Number.parseInt(reward, 10);
  const terms: QuestTerms = { title: title.trim(), description: description.trim(), reward: parsedReward, objectives, inDraw };
  const error = questTermsError(terms);
  const unused = metrics.filter((metric) => !objectives.some((objective) => objective.metric === metric.key));

  function update(index: number, next: Partial<QuestObjectiveTerms>): void {
    setObjectives(objectives.map((objective, i) => (i === index ? { ...objective, ...next } : objective)));
  }

  return (
    <Dialog
      description="Tous les objectifs sont à remplir dans la semaine ; chacun a sa barre de progression chez les membres."
      onOpenChange={(open) => {
        if (!open) onClose();
      }}
      open
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
            {objectives.map((objective, index) => (
              <div className="flex items-center gap-2" key={objective.metric}>
                <select
                  aria-label={`Type de l'objectif ${index + 1}`}
                  className={`${fieldClass} min-w-0 flex-1`}
                  onChange={(e) => update(index, { metric: e.target.value })}
                  value={objective.metric}
                >
                  {metrics
                    .filter((metric) => metric.key === objective.metric || unused.includes(metric))
                    .map((metric) => (
                      <option key={metric.key} value={metric.key}>
                        {metric.label}
                      </option>
                    ))}
                </select>
                <input
                  aria-label={`Cible de l'objectif ${index + 1}`}
                  className={`${fieldClass} w-24`}
                  inputMode="numeric"
                  max={QUEST_LIMITS.maxTarget}
                  min={QUEST_LIMITS.minTarget}
                  onChange={(e) => update(index, { target: Number.parseInt(e.target.value, 10) || 0 })}
                  type="number"
                  value={objective.target === 0 ? "" : objective.target}
                />
                <button
                  aria-label={`Supprimer l'objectif ${index + 1}`}
                  className={buttonVariants({ variant: "ghost" })}
                  disabled={objectives.length === 1}
                  onClick={() => setObjectives(objectives.filter((_, i) => i !== index))}
                  type="button"
                >
                  <Trash2 aria-hidden className="size-4" />
                </button>
              </div>
            ))}
            {objectives.length < QUEST_LIMITS.maxObjectives && unused.length > 0 ? (
              <button
                className={`${buttonVariants({ variant: "ghost" })} justify-self-start`}
                onClick={() => setObjectives([...objectives, { metric: unused[0].key, target: 1 }])}
                type="button"
              >
                <Plus aria-hidden className="size-4" />
                Ajouter un objectif
              </button>
            ) : null}
          </fieldset>

          <label className="flex items-start gap-2 text-sm">
            <input checked={inDraw} className="mt-1" onChange={(e) => setInDraw(e.target.checked)} type="checkbox" />
            <span>
              <span className="font-medium text-foreground">Dans le tirage</span>
              <span className="block text-xs text-muted-foreground">Décochée, la quête ne sort que si on l&apos;épingle à une semaine.</span>
            </span>
          </label>
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
