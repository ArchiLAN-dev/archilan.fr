import Link from "next/link";
import { CheckCircle2, Users } from "lucide-react";

import type { CommunityCollectiveQuest } from "./collective-quest-api";
import { PelleAmount } from "./pelle-amount";
import { formatQuestAmount } from "./quest-amount";

/** Story 41.69: the week's collective quest as the member sees it, beside the week's quests. */
export type CollectiveQuestProgress = {
  key: string;
  label: string;
  description: string;
  reward: number;
  metric: string;
  unit: string;
  target: number;
  /** The whole community's count, each check once. */
  total: number;
  /** The member's share. */
  mine: number;
  /** The share that pays, once the goal is reached. */
  minContribution: number;
  scope: string | null;
  gameSlug: string | null;
  duration: boolean;
  done: boolean;
  paid: boolean;
};

/** « 312 / 500 checks » on a bar, the community's progress. */
export function CollectiveGauge({ total, target, unit, duration, label }: { total: number; target: number; unit: string; duration: boolean; label: string }) {
  const shown = Math.min(total, target);
  const percent = target > 0 ? Math.round((shown / target) * 100) : 0;
  const reached = total >= target;

  return (
    <div className="grid gap-1">
      <p className={`text-right text-xs tabular-nums ${reached ? "text-success" : "text-muted-foreground"}`}>
        {formatQuestAmount(total, duration)} / {formatQuestAmount(target, duration)} {unit}
      </p>
      <div aria-label={label} aria-valuemax={target} aria-valuemin={0} aria-valuenow={shown} className="h-2 overflow-hidden rounded-full bg-surface-2" role="progressbar">
        <div className={`h-full rounded-full transition-[width] ${reached ? "bg-success" : "bg-accent"}`} style={{ width: `${percent}%` }} />
      </div>
    </div>
  );
}

/** What the member's share says: paid, enough, or how much is left to be paid. */
export function shareLine(quest: CollectiveQuestProgress): string {
  const mine = formatQuestAmount(quest.mine, quest.duration);
  if (quest.paid) {
    return `Dont ${mine} de toi : récompense reçue.`;
  }
  if (quest.mine >= quest.minContribution) {
    return quest.done ? `Dont ${mine} de toi : récompense créditée dans quelques minutes.` : `Dont ${mine} de toi : ta part est faite.`;
  }
  return `Dont ${mine} de toi. Il t'en faut ${formatQuestAmount(quest.minContribution, quest.duration)} pour toucher la récompense.`;
}

/** Story 41.69: the collective quest on the member's quests, with their share. */
export function CollectiveQuestRow({ quest }: { quest: CollectiveQuestProgress }) {
  return (
    <section aria-labelledby={`collective-${quest.key}`} className="grid gap-2 rounded-lg border border-border px-3 py-3">
      <div className="flex items-center justify-between gap-3 text-sm">
        <span className="flex min-w-0 items-center gap-2">
          {quest.done ? <CheckCircle2 aria-hidden className="size-4 shrink-0 text-success" /> : <Users aria-hidden className="size-4 shrink-0 text-accent-text" />}
          <span className="font-medium text-foreground" id={`collective-${quest.key}`}>
            Quête collective : {quest.label}
          </span>
        </span>
        <PelleAmount amount={quest.reward} className="shrink-0 text-xs font-semibold text-warning" signed />
      </div>
      {quest.description !== "" ? <p className="text-xs text-muted-foreground">{quest.description}</p> : null}
      <p className="text-xs text-muted-foreground">
        À nous tous
        {quest.scope && quest.gameSlug ? (
          <>
            {" · "}
            <Link className="text-foreground underline-offset-2 hover:underline" href={`/jeux/${encodeURIComponent(quest.gameSlug)}`}>
              {quest.scope}
            </Link>
          </>
        ) : quest.scope ? (
          <span className="text-foreground"> · {quest.scope}</span>
        ) : null}
      </p>
      <CollectiveGauge duration={quest.duration} label={`Quête collective · ${quest.label}`} target={quest.target} total={quest.total} unit={quest.unit} />
      <p className={`text-xs ${quest.mine >= quest.minContribution ? "text-success" : "text-muted-foreground"}`}>{shareLine(quest)}</p>
    </section>
  );
}

const countedFormatter = new Intl.DateTimeFormat("fr-FR", { weekday: "long", hour: "2-digit", minute: "2-digit", timeZone: "Europe/Paris" });

/** Story 41.69: the public gauge on /communaute - no member named. */
export function CommunityCollectiveQuestSection({ quest }: { quest: CommunityCollectiveQuest }) {
  return (
    <section aria-labelledby="collective-quest" className="grid gap-4 border-t border-border pt-12">
      <div className="grid gap-1">
        <h2 className="font-heading text-2xl font-bold text-foreground" id="collective-quest">
          Quête collective de la semaine
        </h2>
        <p className="text-muted-foreground">
          {quest.title} : {quest.objective} à nous tous.
          {quest.reached ? " Objectif atteint, bravo à tous !" : null}
        </p>
        {quest.description !== "" ? <p className="text-sm text-muted-foreground">{quest.description}</p> : null}
      </div>
      <CollectiveGauge duration={quest.duration} label={`Quête collective · ${quest.title}`} target={quest.target} total={quest.total} unit={quest.unit} />
      <p className="text-xs text-muted-foreground">
        Chaque membre qui y prend sa part gagne <PelleAmount amount={quest.reward} className="font-semibold text-warning" />. Compté {countedFormatter.format(new Date(quest.countedAt))}.
      </p>
    </section>
  );
}
