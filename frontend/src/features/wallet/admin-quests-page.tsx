"use client";

import { useState } from "react";
import Link from "next/link";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { CalendarClock, Pin, Plus, Shuffle, Trash2 } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Dialog, DialogBody, DialogFooter } from "@/components/ui/dialog";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import {
  QUEST_LIMITS,
  editQuest,
  fetchAdminQuests,
  objectivesSummary,
  pinQuest,
  setQuestRetired,
  setQuestsPerWeek,
  unpinQuest,
  writeQuest,
  type AdminQuest,
  type AdminQuestWeek,
  type AdminQuests,
  type QuestMetricOption,
  type QuestObjectiveTerms,
  type QuestTerms,
} from "./admin-quests-api";
import { PelleAmount } from "./pelle-amount";

const QUERY_KEY = ["admin-quests"] as const;
const fieldClass = "min-h-9 rounded-lg border border-border bg-background px-3 text-sm text-foreground";
const weekFormatter = new Intl.DateTimeFormat("fr-FR", { day: "numeric", month: "short", timeZone: "Europe/Paris" });

/** The week's first day, « 5 oct. » (its end is the next Monday, so the Sunday before it closes the range). */
export function weekLabel(week: Pick<AdminQuestWeek, "startsAt" | "endsAt">): string {
  const lastDay = new Date(new Date(week.endsAt).getTime() - 1);
  return `${weekFormatter.format(new Date(week.startsAt))} - ${weekFormatter.format(lastDay)}`;
}

type Change = { title: string; description: string; label: string; run: () => Promise<string | null> };

type QuestsView = "weeks" | "types";

const PAGES: Record<QuestsView, { title: string; intro: string; other: { href: string; label: string } }> = {
  weeks: {
    title: "Semaines de quêtes",
    intro:
      "Chaque lundi, les quêtes de la semaine sont tirées au hasard parmi les types « dans le tirage ». Une quête épinglée à une semaine y prend une place, le tirage complète le reste.",
    other: { href: "/admin/quetes/types", label: "Types de quêtes" },
  },
  types: {
    title: "Types de quêtes",
    intro: "Les quêtes que les semaines peuvent servir : leurs objectifs, leur récompense, et si elles sortent au tirage ou seulement épinglées.",
    other: { href: "/admin/quetes/semaines", label: "Semaines de quêtes" },
  },
};

/**
 * The weekly quests, admin side (story 41.15), on two pages: `/admin/quetes/semaines` plans the current and coming
 * weeks, `/admin/quetes/types` writes the quests they serve.
 */
export function AdminQuestsPage({ view }: { view: QuestsView }) {
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: QUERY_KEY, queryFn: fetchAdminQuests, staleTime: DEFAULT_STALE_TIME, retry: false });
  const page = PAGES[view];

  async function after(error: string | null): Promise<string | null> {
    await queryClient.invalidateQueries({ queryKey: QUERY_KEY });
    await queryClient.invalidateQueries({ queryKey: ["my-quests"] });
    return error;
  }

  return (
    <section className="grid gap-6 p-6 md:p-8">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div className="max-w-3xl">
          <h1 className="font-heading text-2xl font-bold text-foreground">{page.title}</h1>
          <p className="mt-1 text-sm text-muted-foreground">{page.intro}</p>
        </div>
        <Link className={buttonVariants({ variant: "secondary" })} href={page.other.href}>
          {page.other.label}
        </Link>
      </header>
      {isLoading ? <p className="text-sm text-muted-foreground">Chargement…</p> : null}
      {!isLoading && !data ? <p className="text-sm text-danger">Impossible de charger les quêtes.</p> : null}
      {data ? view === "weeks" ? <AdminQuestWeeksView data={data} onChange={after} /> : <AdminQuestTypesView data={data} onChange={after} /> : null}
    </section>
  );
}

type ViewProps = { data: AdminQuests; onChange: (error: string | null) => Promise<string | null> };

type StatusMessage = { tone: "ok" | "error"; text: string };

/** A change, its pending state and the line that reports it. */
function useQuestChange(onChange: ViewProps["onChange"]) {
  const [message, setMessage] = useState<StatusMessage | null>(null);
  const [pending, setPending] = useState(false);

  async function apply(run: () => Promise<string | null>, ok: string): Promise<boolean> {
    setPending(true);
    const error = await onChange(await run());
    setPending(false);
    setMessage(error === null ? { tone: "ok", text: ok } : { tone: "error", text: error });
    return error === null;
  }

  return { message, pending, apply };
}

function StatusLine({ message }: { message: StatusMessage | null }) {
  return message ? (
    <p className={`rounded-lg border px-3 py-2 text-sm ${message.tone === "ok" ? "border-success/40 text-success" : "border-danger/40 text-danger"}`} role="status">
      {message.text}
    </p>
  ) : null;
}

/** `/admin/quetes/semaines`: the number of quests a week, and the current and 8 coming weeks. */
export function AdminQuestWeeksView({ data, onChange }: ViewProps) {
  const { message, pending, apply } = useQuestChange(onChange);
  const [change, setChange] = useState<Change | null>(null);

  // A change on the week the members are living is confirmed first; a coming week changes right away.
  function changeWeek(week: AdminQuestWeek, next: Change): void {
    if (week.current) setChange(next);
    else void apply(next.run, next.label);
  }

  const active = data.quests.filter((quest) => !quest.retired);
  const inDraw = active.filter((quest) => quest.inDraw).length;

  return (
    <div className="grid gap-4">
      <StatusLine message={message} />
      <div className="flex flex-wrap items-end justify-between gap-3">
        <p className="text-sm text-muted-foreground">
          {inDraw} type{inDraw > 1 ? "s" : ""} de quête dans le tirage.{" "}
          {active.length === 0 ? (
            <Link className="text-accent-text hover:underline" href="/admin/quetes/types">
              Créer une quête
            </Link>
          ) : null}
        </p>
        <PerWeekForm count={data.questsPerWeek} onSave={(count) => apply(() => setQuestsPerWeek(count), "Nombre de quêtes enregistré.")} />
      </div>
      <ol className="grid gap-3 lg:grid-cols-2" role="list">
        {data.weeks.map((week) => (
          <WeekCard key={week.key} metrics={data.metrics} onChange={(next) => changeWeek(week, next)} perWeek={data.questsPerWeek} quests={active} week={week} />
        ))}
      </ol>

      <ConfirmDialog
        confirmLabel={change?.label ?? "Confirmer"}
        description={change?.description ?? ""}
        icon={CalendarClock}
        onConfirm={() => {
          if (change === null) return;
          void apply(change.run, change.label).then(() => setChange(null));
        }}
        onOpenChange={(open) => {
          if (!open) setChange(null);
        }}
        open={change !== null}
        pending={pending}
        title={change?.title ?? ""}
      />
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

function PerWeekForm({ count, onSave }: { count: number; onSave: (count: number) => Promise<boolean> }) {
  const [value, setValue] = useState(String(count));
  const parsed = Number.parseInt(value, 10);
  const valid = Number.isInteger(parsed) && parsed >= QUEST_LIMITS.minPerWeek && parsed <= QUEST_LIMITS.maxPerWeek;

  return (
    <form
      className="flex items-end gap-2"
      onSubmit={(event) => {
        event.preventDefault();
        void onSave(parsed);
      }}
    >
      <label className="grid gap-1 text-sm">
        <span className="font-medium text-foreground">Quêtes par semaine</span>
        <input
          className={`${fieldClass} w-24`}
          inputMode="numeric"
          max={QUEST_LIMITS.maxPerWeek}
          min={QUEST_LIMITS.minPerWeek}
          onChange={(event) => setValue(event.target.value)}
          type="number"
          value={value}
        />
      </label>
      <button className={buttonVariants({ variant: "secondary" })} disabled={!valid || parsed === count} type="submit">
        Enregistrer
      </button>
    </form>
  );
}

/** One week: its quests (pinned or drawn), what the draw will add, and the admin's hand on it. */
export function WeekCard({
  week,
  quests,
  metrics,
  perWeek,
  onChange,
}: {
  week: AdminQuestWeek;
  quests: AdminQuest[];
  metrics: QuestMetricOption[];
  perWeek: number;
  onChange: (change: Change) => void;
}) {
  const [adding, setAdding] = useState("");
  const served = new Set(week.quests.map((quest) => quest.questId));
  const addable = quests.filter((quest) => !served.has(quest.id));
  const toDraw = Math.max(0, perWeek - week.quests.length);
  const where = week.current ? "la semaine en cours" : `la semaine du ${weekLabel(week)}`;

  return (
    <li className={`grid content-start gap-3 rounded-lg border p-4 ${week.current ? "border-accent/50 bg-accent/5" : "border-border bg-surface"}`}>
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 className="font-semibold text-foreground">
          {weekLabel(week)}
          {week.current ? <span className="ml-2 rounded-full border border-accent/40 px-2 py-0.5 text-xs font-medium text-accent-text">En cours</span> : null}
        </h3>
        <span className="text-xs text-muted-foreground tabular-nums">{week.key}</span>
      </div>

      {week.quests.length === 0 ? <p className="text-sm text-muted-foreground">Aucune quête épinglée.</p> : null}
      <ul className="grid gap-2" role="list">
        {week.quests.map((served) => {
          const replacements = addable.filter((quest) => quest.id !== served.questId);
          return (
            <li className="flex flex-wrap items-center gap-2 text-sm" key={served.questId}>
              {served.origin === "pinned" ? (
                <Pin aria-label="Épinglée" className="size-3.5 shrink-0 text-accent-text" />
              ) : (
                <Shuffle aria-label="Tirée au hasard" className="size-3.5 shrink-0 text-muted-foreground" />
              )}
              <span className="min-w-0 flex-1 truncate text-foreground">{served.title}</span>
              <PelleAmount amount={served.reward} className="text-xs font-semibold text-warning" signed />
              {replacements.length > 0 ? (
                <select
                  aria-label={`Remplacer « ${served.title} »`}
                  className={`${fieldClass} min-h-8 max-w-40 text-xs`}
                  onChange={(event) => {
                    const replacement = replacements.find((quest) => quest.id === event.target.value);
                    event.target.value = "";
                    if (!replacement) return;
                    onChange({
                      title: "Remplacer une quête de la semaine en cours ?",
                      description: `« ${replacement.title} » remplace « ${served.title} » pour ${where}. Les membres le voient tout de suite ; ce qui a déjà été payé reste acquis.`,
                      label: "Quête remplacée.",
                      run: () => pinQuest(week.key, replacement.id, served.questId),
                    });
                  }}
                  value=""
                >
                  <option value="">Remplacer…</option>
                  {replacements.map((quest) => (
                    <option key={quest.id} value={quest.id}>
                      {quest.title}
                    </option>
                  ))}
                </select>
              ) : null}
              <button
                aria-label={`Retirer « ${served.title} » de la semaine`}
                className={buttonVariants({ variant: "ghost" })}
                onClick={() =>
                  onChange({
                    title: "Retirer une quête de la semaine en cours ?",
                    description: `« ${served.title} » quitte ${where} : elle ne paiera plus personne cette semaine. Ce qui a déjà été payé reste acquis.`,
                    label: "Quête retirée de la semaine.",
                    run: () => unpinQuest(week.key, served.questId),
                  })
                }
                type="button"
              >
                <Trash2 aria-hidden className="size-4" />
              </button>
            </li>
          );
        })}
      </ul>

      {!week.drawn ? (
        <p className="text-xs text-muted-foreground">
          {toDraw > 0 ? `Tirage le lundi : ${toDraw} quête${toDraw > 1 ? "s" : ""} au hasard.` : "Semaine complète : pas de tirage."}
        </p>
      ) : null}

      {addable.length > 0 ? (
        <form
          className="flex gap-2"
          onSubmit={(event) => {
            event.preventDefault();
            const quest = addable.find((candidate) => candidate.id === adding);
            if (!quest) return;
            setAdding("");
            onChange({
              title: "Ajouter une quête à la semaine en cours ?",
              description: `« ${quest.title} » (${objectivesSummary(quest.objectives, metrics)}) s'ajoute à ${where}, en plus des quêtes déjà là.`,
              label: "Quête épinglée.",
              run: () => pinQuest(week.key, quest.id),
            });
          }}
        >
          <select aria-label={`Épingler une quête à ${where}`} className={`${fieldClass} min-w-0 flex-1 text-xs`} onChange={(event) => setAdding(event.target.value)} value={adding}>
            <option value="">Épingler une quête…</option>
            {addable.map((quest) => (
              <option key={quest.id} value={quest.id}>
                {quest.title}
                {quest.inDraw ? "" : " (hors tirage)"}
              </option>
            ))}
          </select>
          <button className={buttonVariants({ variant: "secondary" })} disabled={adding === ""} type="submit">
            <Pin aria-hidden className="size-4" />
            Épingler
          </button>
        </form>
      ) : null}
    </li>
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
