"use client";

import { useState } from "react";
import Link from "next/link";
import { CalendarClock, Pin, Plus, Repeat2, Shuffle, X } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Dialog, DialogBody, DialogFooter } from "@/components/ui/dialog";
import { QUEST_LIMITS, objectivesSummary, pinQuest, setQuestsPerWeek, unpinQuest, type AdminQuest, type AdminQuestWeek, type QuestMetricOption, type ServedQuest } from "./admin-quests-api";
import { StatusLine, fieldClass, useQuestChange, weekLabel, type ViewProps } from "./admin-quests-shared";
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

      <div className="flex flex-wrap items-end justify-between gap-3 rounded-lg border border-border bg-surface px-4 py-3">
        <p className="text-sm text-muted-foreground">
          <span className="font-semibold text-foreground">{inDraw}</span> type{inDraw > 1 ? "s" : ""} de quête dans le tirage
          {inDraw < data.questsPerWeek ? (
            <span className="text-warning"> : moins que le nombre par semaine, certaines semaines en auront moins.</span>
          ) : (
            "."
          )}{" "}
          <Link className="text-accent-text hover:underline" href="/admin/quetes/types">
            Gérer les types
          </Link>
        </p>
        <PerWeekForm count={data.questsPerWeek} onSave={(count) => apply(() => setQuestsPerWeek(count), "Nombre de quêtes enregistré.")} />
      </div>

      {current ? (
        <CurrentWeek
          byId={byId}
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
  metrics,
  pending,
  onAdd,
  onReplace,
  onRemove,
}: {
  week: AdminQuestWeek;
  byId: Map<string, AdminQuest>;
  metrics: QuestMetricOption[];
  pending: boolean;
  onAdd: () => void;
  onReplace: (quest: ServedQuest) => void;
  onRemove: (quest: ServedQuest) => void;
}) {
  const total = week.quests.reduce((sum, quest) => sum + quest.reward, 0);

  return (
    <section aria-labelledby="current-week" className="grid gap-3 rounded-xl border border-accent/40 bg-accent/5 p-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="grid gap-0.5">
          <h2 className="flex flex-wrap items-center gap-2 font-heading text-lg font-semibold text-foreground" id="current-week">
            Cette semaine
            <span className="text-sm font-normal text-muted-foreground">{weekLabel(week)}</span>
          </h2>
          <p className="text-xs text-muted-foreground">
            {week.quests.length} quête{week.quests.length > 1 ? "s" : ""} · jusqu&apos;à <PelleAmount amount={total} className="font-semibold text-warning" /> par membre
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
