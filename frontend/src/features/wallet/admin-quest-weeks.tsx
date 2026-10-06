"use client";

import { useState } from "react";
import Link from "next/link";
import { ArrowRight, CalendarClock, Gift, Minus, Pin, Plus, Repeat2, Shuffle, Users, X } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Dialog, DialogBody, DialogFooter } from "@/components/ui/dialog";
import {
  QUEST_LIMITS,
  objectivesSummary,
  pinQuest,
  setQuestSettings,
  unpinQuest,
  type AdminQuest,
  type AdminQuestWeek,
  type PastQuestWeek,
  type QuestMetricOption,
  type ServedQuest,
} from "./admin-quests-api";
import { StatusLine, useQuestChange, weekLabel, type ViewProps } from "./admin-quests-shared";
import { PelleAmount } from "./pelle-amount";

/** What the admin is choosing a quest for: to add to a week, or to put in place of one already there. */
type Picking = { week: AdminQuestWeek; replaces: ServedQuest | null };

type Removal = { week: AdminQuestWeek; quest: ServedQuest };

/**
 * `/admin/quetes/semaines` (story 41.15): the week the members are living first, in full, then the 8 coming ones in
 * a compact list - their pinned quests and how many the Monday draw will add.
 */
export function AdminQuestWeeksView({ data, onChange }: ViewProps) {
  const { message, pending, apply } = useQuestChange(onChange);
  const [picking, setPicking] = useState<Picking | null>(null);
  const [removing, setRemoving] = useState<Removal | null>(null);

  const active = data.quests.filter((quest) => !quest.retired);
  const inDraw = active.filter((quest) => quest.inDraw).length;
  const [current, ...coming] = data.weeks;
  const byId = new Map(data.quests.map((quest) => [quest.id, quest]));

  function remove(week: AdminQuestWeek, quest: ServedQuest): void {
    // The week the members are living is confirmed first; a coming week changes right away.
    if (week.current) setRemoving({ week, quest });
    else void apply(() => unpinQuest(week.key, quest.questId), "Quête retirée de la semaine.");
  }

  return (
    <div className="grid gap-6">
      <StatusLine message={message} />

      <DrawPanel
        chestReward={data.chestReward}
        inDraw={inDraw}
        nextDraw={coming[0]?.startsAt ?? null}
        onSave={(settings) => apply(() => setQuestSettings(settings), "Réglage enregistré.")}
        pending={pending}
        perWeek={data.questsPerWeek}
      />

      {current ? (
        <CurrentWeek
          byId={byId}
          chestReward={data.chestReward}
          metrics={data.metrics}
          onAdd={() => setPicking({ week: current, replaces: null })}
          onRemove={(quest) => remove(current, quest)}
          onReplace={(quest) => setPicking({ week: current, replaces: quest })}
          pending={pending}
          week={current}
        />
      ) : null}

      <section aria-labelledby="coming-weeks" className="grid gap-2">
        <h2 className="font-heading text-lg font-semibold text-foreground" id="coming-weeks">
          À venir
        </h2>
        <ol className="divide-y divide-border overflow-hidden rounded-lg border border-border bg-surface" role="list">
          {coming.map((week) => (
            <ComingWeekRow
              key={week.key}
              onPin={() => setPicking({ week, replaces: null })}
              onUnpin={(quest) => remove(week, quest)}
              pending={pending}
              perWeek={data.questsPerWeek}
              week={week}
            />
          ))}
        </ol>
      </section>

      {data.pastWeeks.length > 0 ? <PastWeeks weeks={data.pastWeeks} /> : null}

      {picking !== null ? (
        <PickQuestDialog
          metrics={data.metrics}
          onClose={() => setPicking(null)}
          onPick={async (quest) => {
            const done = await apply(
              () => pinQuest(picking.week.key, quest.id, picking.replaces?.questId ?? null),
              picking.replaces ? `« ${quest.title} » remplace « ${picking.replaces.title} ».` : `« ${quest.title} » épinglée.`,
            );
            if (done) setPicking(null);
          }}
          pending={pending}
          picking={picking}
          quests={active.filter((quest) => !picking.week.quests.some((served) => served.questId === quest.id))}
        />
      ) : null}

      <ConfirmDialog
        confirmLabel="Retirer de la semaine"
        description={
          removing
            ? `« ${removing.quest.title} » quitte la semaine en cours : elle ne paiera plus personne cette semaine. Ce qui a déjà été payé reste acquis.`
            : ""
        }
        icon={CalendarClock}
        onConfirm={() => {
          if (removing === null) return;
          void apply(() => unpinQuest(removing.week.key, removing.quest.questId), "Quête retirée de la semaine.").then(() => setRemoving(null));
        }}
        onOpenChange={(open) => {
          if (!open) setRemoving(null);
        }}
        open={removing !== null}
        pending={pending}
        title="Retirer une quête de la semaine en cours ?"
        tone="danger"
      />
    </div>
  );
}

const ORIGIN: Record<ServedQuest["origin"], { label: string; icon: typeof Pin; tone: string }> = {
  pinned: { label: "Épinglée", icon: Pin, tone: "border-accent/40 text-accent-text" },
  drawn: { label: "Tirée", icon: Shuffle, tone: "border-border text-muted-foreground" },
};

export function OriginBadge({ origin }: { origin: ServedQuest["origin"] }) {
  const { label, icon: Icon, tone } = ORIGIN[origin];
  return (
    <span className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium ${tone}`}>
      <Icon aria-hidden className="size-3" />
      {label}
    </span>
  );
}

/** The week the members are living: each quest with its objectives, and what pinning changes now. */
export function CurrentWeek({
  week,
  byId,
  chestReward,
  metrics,
  pending,
  onAdd,
  onReplace,
  onRemove,
}: {
  week: AdminQuestWeek;
  byId: Map<string, AdminQuest>;
  chestReward: number;
  metrics: QuestMetricOption[];
  pending: boolean;
  onAdd: () => void;
  onReplace: (quest: ServedQuest) => void;
  onRemove: (quest: ServedQuest) => void;
}) {
  // Story 41.16: the most a member can earn this week, chest included.
  const chest = week.quests.length > 0 ? chestReward : 0;
  const total = week.quests.reduce((sum, quest) => sum + quest.reward, 0) + chest;

  return (
    <section aria-labelledby="current-week" className="grid gap-3 rounded-xl border border-accent/40 bg-accent/5 p-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="grid gap-0.5">
          <h2 className="flex flex-wrap items-center gap-2 font-heading text-lg font-semibold text-foreground" id="current-week">
            Cette semaine
            <span className="text-sm font-normal text-muted-foreground">{weekLabel(week)}</span>
          </h2>
          <p className="text-xs text-muted-foreground">
            {week.quests.length} quête{week.quests.length > 1 ? "s" : ""} · jusqu&apos;à <PelleAmount amount={total} className="font-semibold text-warning" /> par membre{chest > 0 ? ", coffre compris" : ""}
          </p>
        </div>
        <button className={buttonVariants({ variant: "secondary" })} disabled={pending} onClick={onAdd} type="button">
          <Plus aria-hidden className="size-4" />
          Ajouter une quête
        </button>
      </div>

      {week.quests.length === 0 ? (
        <p className="rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">Aucune quête cette semaine.</p>
      ) : (
        <ul className="grid gap-2" role="list">
          {week.quests.map((served) => {
            const quest = byId.get(served.questId);
            return (
              <li className="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-surface px-4 py-3" key={served.questId}>
                <div className="grid min-w-0 flex-1 gap-0.5">
                  <p className="flex flex-wrap items-center gap-2 font-semibold text-foreground">
                    {served.title}
                    <OriginBadge origin={served.origin} />
                    {served.retired ? <span className="rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground">Type retiré</span> : null}
                  </p>
                  {quest ? <p className="text-xs text-muted-foreground">{objectivesSummary(quest.objectives, metrics)}</p> : null}
                </div>
                <PelleAmount amount={served.reward} className="text-sm font-semibold text-warning" signed />
                <div className="flex gap-1">
                  <button className={buttonVariants({ variant: "ghost" })} disabled={pending} onClick={() => onReplace(served)} type="button">
                    <Repeat2 aria-hidden className="size-4" />
                    Remplacer
                  </button>
                  <button aria-label={`Retirer « ${served.title} » de la semaine`} className={buttonVariants({ variant: "ghost" })} disabled={pending} onClick={() => onRemove(served)} type="button">
                    <X aria-hidden className="size-4" />
                  </button>
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </section>
  );
}

/** A coming week on one line: its pinned quests (removable), and the places the Monday draw will fill. */
export function ComingWeekRow({
  week,
  perWeek,
  pending,
  onPin,
  onUnpin,
}: {
  week: AdminQuestWeek;
  perWeek: number;
  pending: boolean;
  onPin: () => void;
  onUnpin: (quest: ServedQuest) => void;
}) {
  const toDraw = week.drawn ? 0 : Math.max(0, perWeek - week.quests.length);

  return (
    <li className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
      <span className="w-32 shrink-0 text-sm font-medium text-foreground">{weekLabel(week)}</span>
      <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2">
        {week.quests.map((served) => (
          <span className="inline-flex items-center gap-1 rounded-full border border-accent/40 bg-accent/10 py-0.5 pl-2.5 pr-1 text-xs font-medium text-accent-text" key={served.questId}>
            <Pin aria-hidden className="size-3" />
            {served.title}
            <button
              aria-label={`Désépingler « ${served.title} »`}
              className="rounded-full p-0.5 hover:bg-accent/20"
              disabled={pending}
              onClick={() => onUnpin(served)}
              type="button"
            >
              <X aria-hidden className="size-3" />
            </button>
          </span>
        ))}
        {toDraw > 0 ? (
          <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
            <Shuffle aria-hidden className="size-3" />
            {week.quests.length > 0 ? "+ " : ""}
            {toDraw} au tirage
          </span>
        ) : null}
        {toDraw === 0 && week.quests.length === 0 ? <span className="text-xs text-muted-foreground">Aucune quête</span> : null}
      </div>
      <button className={buttonVariants({ variant: "ghost" })} disabled={pending} onClick={onPin} type="button">
        <Pin aria-hidden className="size-4" />
        Épingler
      </button>
    </li>
  );
}

/** Choosing the quest to pin, or to put in place of another: each with its objectives, reward and draw. */
export function PickQuestDialog({
  picking,
  quests,
  metrics,
  pending,
  onPick,
  onClose,
}: {
  picking: Picking;
  quests: AdminQuest[];
  metrics: QuestMetricOption[];
  pending: boolean;
  onPick: (quest: AdminQuest) => Promise<void>;
  onClose: () => void;
}) {
  const [chosen, setChosen] = useState<string | null>(null);
  const quest = quests.find((candidate) => candidate.id === chosen) ?? null;
  const when = picking.week.current ? "la semaine en cours" : `la semaine du ${weekLabel(picking.week)}`;

  return (
    <Dialog
      description={
        picking.replaces
          ? `La quête choisie remplace « ${picking.replaces.title} » pour ${when}.`
          : `La quête choisie est épinglée à ${when}${picking.week.current ? ", en plus des quêtes déjà là" : " et y prend une place du tirage"}.`
      }
      onOpenChange={(open) => {
        if (!open) onClose();
      }}
      open
      title={picking.replaces ? `Remplacer « ${picking.replaces.title} »` : `Épingler une quête`}
    >
      <DialogBody className="grid gap-2">
        {quests.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            Toutes les quêtes sont déjà dans cette semaine.{" "}
            <Link className="text-accent-text hover:underline" href="/admin/quetes/types">
              Créer une quête
            </Link>
          </p>
        ) : (
          <ul className="grid max-h-80 gap-2 overflow-y-auto" role="radiogroup">
            {quests.map((candidate) => (
              <li key={candidate.id}>
                <label
                  className={`flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 ${chosen === candidate.id ? "border-accent bg-accent/10" : "border-border hover:border-accent/50"}`}
                >
                  <input checked={chosen === candidate.id} className="sr-only" name="quest" onChange={() => setChosen(candidate.id)} type="radio" />
                  <span className="grid min-w-0 flex-1 gap-0.5">
                    <span className="flex flex-wrap items-center gap-2 text-sm font-semibold text-foreground">
                      {candidate.title}
                      {candidate.inDraw ? null : <span className="rounded-full border border-border px-2 py-0.5 text-xs font-normal text-muted-foreground">Hors tirage</span>}
                    </span>
                    <span className="text-xs text-muted-foreground">{objectivesSummary(candidate.objectives, metrics)}</span>
                  </span>
                  <PelleAmount amount={candidate.reward} className="text-xs font-semibold text-warning" signed />
                </label>
              </li>
            ))}
          </ul>
        )}
        {picking.week.current && quest ? (
          <p className="text-xs text-warning">Les membres voient le changement tout de suite ; ce qui a déjà été payé reste acquis.</p>
        ) : null}
      </DialogBody>
      <DialogFooter>
        <button className={buttonVariants({ variant: "ghost" })} onClick={onClose} type="button">
          Annuler
        </button>
        <button className={buttonVariants({ variant: "primary" })} disabled={quest === null || pending} onClick={() => quest && void onPick(quest)} type="button">
          {picking.replaces ? "Remplacer" : "Épingler"}
        </button>
      </DialogFooter>
    </Dialog>
  );
}

const drawDay = new Intl.DateTimeFormat("fr-FR", { weekday: "long", day: "numeric", month: "long", timeZone: "Europe/Paris" });

/**
 * How the weeks fill (story 41.15), as figures: the quests a week (changed in place), the types the draw picks from,
 * when the next draw happens, and (story 41.16) the chest for doing every quest - with the rule in one line, and a
 * warning when the draw falls short.
 */
export function DrawPanel({
  perWeek,
  chestReward,
  inDraw,
  nextDraw,
  pending,
  onSave,
}: {
  perWeek: number;
  chestReward: number;
  inDraw: number;
  nextDraw: string | null;
  pending: boolean;
  onSave: (settings: { questsPerWeek?: number; chestReward?: number }) => Promise<boolean>;
}) {
  const [count, setCount] = useState(perWeek);
  // Against the number being edited, so the warning shows before saving.
  const short = inDraw < count;

  return (
    <section aria-label="Tirage des quêtes" className="grid gap-4 rounded-xl border border-border bg-surface p-5">
      <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
        <SettingStepper
          hint="Vaut pour les semaines pas encore tirées."
          label="Quêtes par semaine"
          max={QUEST_LIMITS.maxPerWeek}
          min={QUEST_LIMITS.minPerWeek}
          onChange={setCount}
          onSave={(value) => onSave({ questsPerWeek: value })}
          pending={pending}
          saved={perWeek}
          step={1}
          value={count}
        />

        <div className="grid content-start gap-2">
          <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Types dans le tirage</p>
          <p className={`font-heading text-2xl font-bold tabular-nums ${short ? "text-warning" : "text-foreground"}`}>{inDraw}</p>
          <Link className="inline-flex items-center gap-1 text-xs text-accent-text hover:underline" href="/admin/quetes/types">
            Gérer les types
            <ArrowRight aria-hidden className="size-3" />
          </Link>
        </div>

        <div className="grid content-start gap-2">
          <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Prochain tirage</p>
          <p className="font-heading text-lg font-semibold text-foreground first-letter:uppercase">{nextDraw ? drawDay.format(new Date(nextDraw)) : "-"}</p>
          <p className="text-xs text-muted-foreground">à minuit, heure de Paris</p>
        </div>

        <ChestSetting onSave={(value) => onSave({ chestReward: value })} pending={pending} saved={chestReward} />
      </div>

      <p className="flex items-start gap-2 border-t border-border pt-3 text-xs text-muted-foreground">
        <Shuffle aria-hidden className="mt-0.5 size-3.5 shrink-0" />
        <span>
          Chaque lundi, la semaine prend ses quêtes épinglées puis tire le reste au hasard parmi les types « dans le tirage ».
          {short ? (
            <span className="text-warning">
              {" "}
              Il y a moins de types dans le tirage que de quêtes par semaine : les semaines sans épinglée en auront {inDraw}.
            </span>
          ) : null}
        </span>
      </p>
    </section>
  );
}

function ChestSetting({ saved, pending, onSave }: { saved: number; pending: boolean; onSave: (value: number) => Promise<boolean> }) {
  const [value, setValue] = useState(saved);

  return (
    <SettingStepper
      hint={value === 0 ? "Pas de coffre : rien en plus pour toutes les quêtes." : "En plus, pour qui fait toutes les quêtes de la semaine."}
      icon={Gift}
      label="Coffre de la semaine"
      max={QUEST_LIMITS.maxChest}
      min={0}
      onChange={setValue}
      onSave={onSave}
      pending={pending}
      saved={saved}
      step={10}
      unit="pelles"
      value={value}
    />
  );
}

/** A number set in place with - and +: « Enregistrer » and « Annuler » only once it differs from the saved one. */
export function SettingStepper({
  label,
  hint,
  value,
  saved,
  min,
  max,
  step,
  unit,
  icon: Icon,
  pending,
  onChange,
  onSave,
}: {
  label: string;
  hint: string;
  value: number;
  saved: number;
  min: number;
  max: number;
  step: number;
  unit?: string;
  icon?: typeof Gift;
  pending: boolean;
  onChange: (value: number) => void;
  onSave: (value: number) => Promise<boolean>;
}) {
  const id = `setting-${label.replace(/\W+/g, "-").toLowerCase()}`;

  return (
    <div className="grid content-start gap-2">
      <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground" id={id}>
        {Icon ? <Icon aria-hidden className="size-3.5" /> : null}
        {label}
      </p>
      <div className="flex flex-wrap items-center gap-2">
        <div aria-labelledby={id} className="inline-flex items-center rounded-lg border border-border bg-background" role="group">
          <button
            aria-label={`${label} : moins`}
            className="grid size-9 place-items-center text-muted-foreground hover:text-foreground disabled:opacity-40"
            disabled={value <= min}
            onClick={() => onChange(Math.max(min, value - step))}
            type="button"
          >
            <Minus aria-hidden className="size-4" />
          </button>
          <output aria-live="polite" className="min-w-8 px-1 text-center font-heading text-2xl font-bold tabular-nums text-foreground">
            {value}
          </output>
          <button
            aria-label={`${label} : plus`}
            className="grid size-9 place-items-center text-muted-foreground hover:text-foreground disabled:opacity-40"
            disabled={value >= max}
            onClick={() => onChange(Math.min(max, value + step))}
            type="button"
          >
            <Plus aria-hidden className="size-4" />
          </button>
        </div>
        {unit ? <span className="text-xs text-muted-foreground">{unit}</span> : null}
        {value !== saved ? (
          <>
            <button className={buttonVariants({ variant: "primary" })} disabled={pending} onClick={() => void onSave(value)} type="button">
              Enregistrer
            </button>
            <button className={buttonVariants({ variant: "ghost" })} onClick={() => onChange(saved)} type="button">
              Annuler
            </button>
          </>
        ) : null}
      </div>
      <p className="text-xs text-muted-foreground">{hint}</p>
    </div>
  );
}

/**
 * Story 41.16: the 4 weeks just past - each quest with the members who did it, the chests opened and the pelles
 * paid. What tells whether a quest is too hard, too easy, or pays too much.
 */
export function PastWeeks({ weeks }: { weeks: PastQuestWeek[] }) {
  return (
    <section aria-labelledby="past-weeks" className="grid gap-2">
      <h2 className="font-heading text-lg font-semibold text-foreground" id="past-weeks">
        Semaines passées
      </h2>
      <ol className="divide-y divide-border overflow-hidden rounded-lg border border-border bg-surface" role="list">
        {weeks.map((week) => (
          <li className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3" key={week.key}>
            <span className="w-32 shrink-0 text-sm font-medium text-foreground">{weekLabel(week)}</span>
            <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2">
              {week.quests.length === 0 ? <span className="text-xs text-muted-foreground">Aucune quête</span> : null}
              {week.quests.map((quest) => (
                <span className="inline-flex items-center gap-1.5 rounded-full border border-border px-2.5 py-0.5 text-xs text-foreground" key={quest.questId}>
                  {quest.title}
                  <span className={`inline-flex items-center gap-0.5 font-semibold tabular-nums ${quest.members > 0 ? "text-success" : "text-muted-foreground"}`}>
                    <Users aria-hidden className="size-3" />
                    {quest.members}
                    <span className="sr-only"> membre{quest.members > 1 ? "s" : ""}</span>
                  </span>
                </span>
              ))}
            </div>
            <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
              <Gift aria-hidden className="size-3.5" />
              {week.chests} coffre{week.chests > 1 ? "s" : ""}
            </span>
            <PelleAmount amount={week.pelles} className="w-20 text-right text-xs font-semibold text-warning" />
          </li>
        ))}
      </ol>
    </section>
  );
}
