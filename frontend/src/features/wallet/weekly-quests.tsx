"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { CheckCircle2, Circle, Flame, Gift, Sparkles } from "lucide-react";

import { isCosmeticRewardView, type CosmeticRewardView } from "@/features/community/cosmetic-reward-picker";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { hasBooleanProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";
import { CollectiveQuestRow, type CollectiveQuestProgress } from "./collective-quest";
import { PelleAmount } from "./pelle-amount";
import { formatQuestAmount } from "./quest-amount";

/** Story 41.15: one objective of a quest, and where the member stands on it this week. */
export type QuestObjectiveProgress = {
  metric: string;
  label: string;
  unit: string;
  target: number;
  /** Story 41.73: null until the progress is read. */
  current: number | null;
  scope?: string | null;
  /** Story 41.67: the game aimed at (drawn for the week, maybe), to link its page. */
  gameSlug?: string | null;
  /** Story 41.64: target and current are minutes, shown in hours. */
  duration: boolean;
};

/** Story 41.6: one quest of the week. Story 41.15: written by the admins, with 1 to 5 objectives. */
export type WeeklyQuest = {
  key: string;
  label: string;
  description: string;
  reward: number;
  done: boolean;
  paid: boolean;
  objectives: QuestObjectiveProgress[];
  /** Story 41.28: the cosmetic it unlocks the first time (absent from an older API). */
  cosmetic?: CosmeticRewardView | null;
  /** Story 41.68: its tiers on its only objective, each reached and paid; null (or absent) for a quest done or not. */
  tiers?: QuestTierProgress[] | null;
};

export type QuestTierProgress = { target: number; reward: number; reached: boolean; paid: boolean };

/** Story 41.16: the chest for doing every quest of the week; null when there is none. */
export type QuestChest = { reward: number; done: number | null; total: number; paid: boolean };

/** Story 41.17: one of the member's weeks before this one. */
export type QuestWeekHistory = { week: string; startsAt: string; endsAt: string; done: number; served: number; chest: boolean; pelles: number };

/** Story 41.34: the weeks in a row with the chest opened; the week in progress counts once its chest is open. */
export type QuestStreak = { current: number; best: number; thisWeek: boolean };

export type WeeklyQuests = {
  week: string;
  renewsAt: string;
  /** Story 41.73: false on the quests alone, before the progress is read; absent from an older API (read with it). */
  progress?: boolean;
  quests: WeeklyQuest[];
  /** Story 41.69: the week's collective quest, apart; null (or absent from an older API) without one. */
  collective?: CollectiveQuestProgress | null;
  chest: QuestChest | null;
  history: QuestWeekHistory[];
  /** Absent from an older API. */
  streak?: QuestStreak;
};

function isStreak(v: unknown): v is QuestStreak {
  return typeof v === "object" && v !== null && hasNumberProp(v, "current") && hasNumberProp(v, "best") && hasBooleanProp(v, "thisWeek");
}

function isHistory(v: unknown): v is QuestWeekHistory {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "week") &&
    hasStringProp(v, "startsAt") &&
    hasStringProp(v, "endsAt") &&
    hasNumberProp(v, "done") &&
    hasNumberProp(v, "served") &&
    hasBooleanProp(v, "chest") &&
    hasNumberProp(v, "pelles")
  );
}

export function isCollectiveQuest(v: unknown): v is CollectiveQuestProgress {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "key") &&
    hasStringProp(v, "label") &&
    hasStringProp(v, "description") &&
    hasNumberProp(v, "reward") &&
    hasStringProp(v, "metric") &&
    hasStringProp(v, "unit") &&
    hasNumberProp(v, "target") &&
    "total" in v &&
    (v.total === null || typeof v.total === "number") &&
    "mine" in v &&
    (v.mine === null || typeof v.mine === "number") &&
    hasNumberProp(v, "minContribution") &&
    "scope" in v &&
    (v.scope === null || typeof v.scope === "string") &&
    "gameSlug" in v &&
    (v.gameSlug === null || typeof v.gameSlug === "string") &&
    hasBooleanProp(v, "duration") &&
    "done" in v &&
    (v.done === null || typeof v.done === "boolean") &&
    hasBooleanProp(v, "paid")
  );
}

function isChest(v: unknown): v is QuestChest {
  return (
    typeof v === "object" &&
    v !== null &&
    hasNumberProp(v, "reward") &&
    "done" in v &&
    (v.done === null || typeof v.done === "number") &&
    hasNumberProp(v, "total") &&
    hasBooleanProp(v, "paid")
  );
}

function isObjective(v: unknown): v is QuestObjectiveProgress {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "metric") &&
    hasStringProp(v, "label") &&
    hasStringProp(v, "unit") &&
    hasNumberProp(v, "target") &&
    "current" in v &&
    (v.current === null || typeof v.current === "number") &&
    hasBooleanProp(v, "duration") &&
    (!("scope" in v) || v.scope === null || typeof v.scope === "string") &&
    (!("gameSlug" in v) || v.gameSlug === null || typeof v.gameSlug === "string")
  );
}

function isQuest(v: unknown): v is WeeklyQuest {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "key") &&
    hasStringProp(v, "label") &&
    hasStringProp(v, "description") &&
    hasNumberProp(v, "reward") &&
    hasBooleanProp(v, "done") &&
    hasBooleanProp(v, "paid") &&
    "objectives" in v &&
    Array.isArray(v.objectives) &&
    v.objectives.every(isObjective) &&
    (!("cosmetic" in v) || v.cosmetic === null || isCosmeticRewardView(v.cosmetic)) &&
    (!("tiers" in v) || v.tiers === null || (Array.isArray(v.tiers) && v.tiers.every(isTier)))
  );
}

function isTier(v: unknown): v is QuestTierProgress {
  return typeof v === "object" && v !== null && hasNumberProp(v, "target") && hasNumberProp(v, "reward") && hasBooleanProp(v, "reached") && hasBooleanProp(v, "paid");
}

/** Story 41.68: the most a quest pays, every tier included. */
export function questMaxReward(quest: WeeklyQuest): number {
  return quest.tiers && quest.tiers.length > 0 ? quest.tiers.reduce((sum, tier) => sum + tier.reward, 0) : quest.reward;
}

export function isWeeklyQuests(v: unknown): v is WeeklyQuests {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "week") &&
    hasStringProp(v, "renewsAt") &&
    (!("progress" in v) || typeof v.progress === "boolean") &&
    "quests" in v &&
    Array.isArray(v.quests) &&
    v.quests.every(isQuest) &&
    (!("collective" in v) || v.collective === null || isCollectiveQuest(v.collective)) &&
    "chest" in v &&
    (v.chest === null || isChest(v.chest)) &&
    "history" in v &&
    Array.isArray(v.history) &&
    v.history.every(isHistory) &&
    (!("streak" in v) || isStreak(v.streak))
  );
}

export async function fetchMyQuests(): Promise<WeeklyQuests | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/me/quests`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return isWeeklyQuests(payload) ? payload : null;
  } catch {
    return null;
  }
}

/** Story 41.73: the quests with the member's progress, read apart because it counts the week's games. Throws when unavailable. */
export async function fetchMyQuestsProgress(): Promise<WeeklyQuests> {
  const res = await apiFetch(`${env.apiBaseUrl}/me/quests/progress`);
  if (!res.ok) throw new Error(`quests progress: ${res.status}`);
  const payload: unknown = await res.json();
  if (!isWeeklyQuests(payload)) throw new Error("quests progress: unexpected payload");
  return payload;
}

/** Story 41.73: whether these quests still wait for their progress (an older API sends it with them). */
export function awaitsProgress(quests: WeeklyQuests): boolean {
  return quests.progress === false;
}

const renewFormatter = new Intl.DateTimeFormat("fr-FR", { weekday: "long", day: "numeric", month: "long", timeZone: "Europe/Paris" });

function useMyQuests() {
  return useQuery({ queryKey: ["my-quests"], queryFn: fetchMyQuests, staleTime: DEFAULT_STALE_TIME, retry: false });
}

/** Story 41.73: the progress apart, under the same key prefix so an admin's change refreshes both. */
function useMyQuestsProgress() {
  return useQuery({ queryKey: ["my-quests", "progress"], queryFn: fetchMyQuestsProgress, staleTime: DEFAULT_STALE_TIME, retry: false });
}

/** Story 41.73: the quests as soon as they come, then with their progress. */
export function WeeklyQuestsPanel() {
  const { data: quests, isPending, refetch } = useMyQuests();
  const progress = useMyQuestsProgress();
  const data = progress.data ?? quests;

  if (data) {
    return <WeeklyQuestsView onRetryProgress={() => void progress.refetch()} progressFailed={progress.isError} quests={data} />;
  }
  // Hotfix v1.0.1: the block no longer stays silently empty while the quests load, or when they fail to.
  if (isPending || progress.isPending) {
    return (
      <p className="text-sm text-muted-foreground" role="status">
        Chargement des quêtes de la semaine…
      </p>
    );
  }

  return (
    <p className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground" role="status">
      Impossible de charger les quêtes de la semaine pour le moment.
      <button
        className="font-semibold text-accent-text underline"
        onClick={() => {
          void refetch();
          void progress.refetch();
        }}
        type="button"
      >
        Réessayer
      </button>
    </p>
  );
}

/** Story 41.21: the history in a block of its own. Story 41.34: at the foot of the page, after the movements. */
export function QuestHistoryPanel() {
  const { data } = useMyQuests();

  return data && data.history.some((week) => week.served > 0) ? <QuestHistory weeks={data.history} /> : null;
}

/**
 * The quests of the week on « Mon portefeuille » (story 41.6): what each pays, done or not, paid or about to be.
 * Story 41.15: one progress bar per objective.
 */
export function WeeklyQuestsView({
  quests,
  progressFailed = false,
  onRetryProgress,
}: {
  quests: WeeklyQuests;
  /** Story 41.73: the progress could not be read; the quests stay shown. */
  progressFailed?: boolean;
  onRetryProgress?: () => void;
}) {
  const waiting = awaitsProgress(quests);

  return (
    <section aria-labelledby="weekly-quests" className="grid gap-3 rounded-xl border border-border p-5">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="font-heading text-xl font-semibold text-foreground" id="weekly-quests">
          Quêtes de la semaine
        </h2>
        <p className="text-xs text-muted-foreground">Nouvelles quêtes le {renewFormatter.format(new Date(quests.renewsAt))}</p>
      </div>
      {/* Story 41.73: the quests first, their progress when it is counted. */}
      {waiting && progressFailed ? (
        <p className="flex flex-wrap items-center gap-2 rounded-lg border border-dashed border-border px-3 py-2 text-xs text-muted-foreground" role="status">
          Progression indisponible pour le moment.
          {onRetryProgress ? (
            <button className="font-semibold text-accent-text underline" onClick={onRetryProgress} type="button">
              Réessayer
            </button>
          ) : null}
        </p>
      ) : waiting ? (
        <p className="sr-only" role="status">
          Calcul de ta progression…
        </p>
      ) : null}
      {quests.quests.length === 0 && !quests.collective ? (
        <p className="text-sm text-muted-foreground">Pas de quête cette semaine.</p>
      ) : (
        <ul className="grid gap-4">
          {quests.quests.map((quest) => (
            <li className="grid gap-2" key={quest.key}>
              <div className="flex items-center justify-between gap-3 text-sm">
                <span className="flex min-w-0 items-center gap-2">
                  {quest.done ? (
                    <CheckCircle2 aria-hidden className="size-4 shrink-0 text-success" />
                  ) : waiting && !progressFailed ? (
                    <Circle aria-hidden className="size-4 shrink-0 animate-pulse text-muted-foreground motion-reduce:animate-none" />
                  ) : (
                    <Circle aria-hidden className="size-4 shrink-0 text-muted-foreground" />
                  )}
                  <span className={`font-medium ${quest.done ? "text-foreground" : "text-muted-foreground"}`}>{quest.label}</span>
                  <span className="sr-only">{quest.done ? "faite" : "à faire"}</span>
                  {quest.done && !quest.paid ? <span className="text-xs text-muted-foreground">(créditée dans quelques minutes)</span> : null}
                </span>
                {quest.tiers && quest.tiers.length > 0 ? (
                  <span className="shrink-0 text-xs text-muted-foreground">
                    jusqu&apos;à <PelleAmount amount={questMaxReward(quest)} className="font-semibold" kind="gold" />
                  </span>
                ) : (
                  <PelleAmount amount={quest.reward} className="shrink-0 text-xs font-semibold" kind="gold" signed />
                )}
              </div>
              {quest.description !== "" ? <p className="pl-6 text-xs text-muted-foreground">{quest.description}</p> : null}
              {/* Story 41.28: the cosmetic it unlocks. */}
              {quest.cosmetic ? (
                <p className="flex items-center gap-1.5 pl-6 text-xs text-accent-text">
                  <Sparkles aria-hidden className="size-3.5" />
                  Débloque : {quest.cosmetic.label}
                </p>
              ) : null}
              <ul className="grid gap-1.5 pl-6">
                {quest.objectives.map((objective) => (
                  <ObjectiveBar done={quest.done} failed={progressFailed} key={objective.metric} objective={objective} tiers={quest.tiers ?? null} />
                ))}
              </ul>
            </li>
          ))}
        </ul>
      )}
      {quests.chest ? <ChestRow chest={quests.chest} failed={progressFailed} /> : null}
      {/* Story 41.69: after the chest it does not count for. */}
      {quests.collective ? <CollectiveQuestRow failed={progressFailed} quest={quests.collective} /> : null}
      {quests.streak && (quests.chest || quests.streak.current > 0) ? <StreakRow streak={quests.streak} /> : null}
    </section>
  );
}

/** Story 41.34: the run of chests in progress, what keeps it going, and the record when it is higher. */
export function StreakRow({ streak }: { streak: QuestStreak }) {
  const alive = streak.current > 0;
  const hint = !alive
    ? "Ouvre le coffre de la semaine pour lancer une série."
    : streak.thisWeek
      ? null
      : "Ouvre le coffre de cette semaine pour la prolonger.";

  return (
    <div className="flex flex-wrap items-center gap-3 rounded-lg border border-border px-3 py-2 text-sm">
      <Flame aria-hidden className={`size-4 shrink-0 ${alive ? "text-warning" : "text-muted-foreground"}`} />
      <span className="min-w-0 flex-1">
        <span className="font-medium text-foreground">
          {alive ? `Série : ${streak.current} semaine${streak.current > 1 ? "s" : ""} d'affilée` : "Pas de série en cours"}
        </span>
        {hint ? <span className="block text-xs text-muted-foreground">{hint}</span> : null}
      </span>
      {streak.best > streak.current ? (
        <span className="shrink-0 text-xs text-muted-foreground">
          Record : {streak.best} semaine{streak.best > 1 ? "s" : ""}
        </span>
      ) : null}
    </div>
  );
}

/**
 * « 3 / 5 checks », capped at the target: a paid quest shows full even if the week's count is read later. Story
 * 41.68: a quest with tiers goes up to its last tier, each tier marked on the same bar.
 */
export function ObjectiveBar({
  objective,
  done,
  tiers = null,
  failed = false,
}: {
  objective: QuestObjectiveProgress;
  done: boolean;
  tiers?: QuestTierProgress[] | null;
  /** Story 41.73: the progress could not be read - no count, no skeleton. */
  failed?: boolean;
}) {
  const steps = tiers && tiers.length > 0 ? tiers : null;
  const top = steps ? (steps[steps.length - 1]?.target ?? objective.target) : objective.target;
  // A tier paid shows reached, even if the week's count is read later.
  const floor = steps ? Math.max(0, ...steps.filter((tier) => tier.paid || tier.reached).map((tier) => tier.target)) : done ? top : 0;
  const shown = Math.min(Math.max(objective.current ?? 0, floor), top);
  const percent = top > 0 ? Math.round((shown / top) * 100) : 0;
  const reached = shown >= top;
  // Story 41.73: a count not read yet - a skeleton, unless the quest is paid (full whatever the count).
  const unknown = objective.current === null && !reached;

  return (
    <li className="grid gap-1">
      <div className="flex items-baseline justify-between gap-2 text-xs">
        <span className="text-muted-foreground">
          {objective.label}
          {/* Story 41.18: the game or event the objective counts in. */}
          {/* Story 41.67: a game links its page - the drawn one is often new to the member. */}
          {objective.scope && objective.gameSlug ? (
            <span className="text-foreground">
              {" · "}
              <Link className="underline-offset-2 hover:underline" href={`/jeux/${encodeURIComponent(objective.gameSlug)}`}>
                {objective.scope}
              </Link>
            </span>
          ) : objective.scope ? (
            <span className="text-foreground"> · {objective.scope}</span>
          ) : null}
        </span>
        {unknown ? (
          <span className="tabular-nums text-muted-foreground">
            {failed ? "?" : <span aria-hidden className="inline-block h-3 w-8 animate-pulse rounded bg-surface-2 align-middle motion-reduce:animate-none" />}
            {" / "}
            {formatQuestAmount(top, objective.duration)} {objective.unit}
          </span>
        ) : (
          <span className={`tabular-nums ${reached ? "text-success" : "text-muted-foreground"}`}>
            {formatQuestAmount(shown, objective.duration)} / {formatQuestAmount(top, objective.duration)} {objective.unit}
          </span>
        )}
      </div>
      {unknown ? (
        <div
          aria-hidden
          className={`h-1.5 overflow-hidden rounded-full bg-surface-2 ${failed ? "" : "animate-pulse motion-reduce:animate-none"}`}
          data-testid="objective-skeleton"
        />
      ) : (
      <div
        aria-label={objective.scope ? `${objective.label} · ${objective.scope}` : objective.label}
        aria-valuemax={top}
        aria-valuemin={0}
        aria-valuenow={shown}
        className="h-1.5 overflow-hidden rounded-full bg-surface-2"
        role="progressbar"
      >
        <div className={`h-full rounded-full transition-[width] ${reached ? "bg-success" : "bg-accent"}`} style={{ width: `${percent}%` }} />
      </div>
      )}
      {steps ? (
        <ol aria-label="Paliers" className="flex flex-wrap gap-x-3 gap-y-0.5 text-xs">
          {steps.map((tier, index) => (
            <li className={`inline-flex items-center gap-1 ${tier.reached ? "text-success" : "text-muted-foreground"}`} key={`palier-${index + 1}`}>
              {tier.reached ? <CheckCircle2 aria-hidden className="size-3" /> : <Circle aria-hidden className="size-3" />}
              <span className="sr-only">{tier.reached ? "Palier atteint : " : "Palier : "}</span>
              {formatQuestAmount(tier.target, objective.duration)} {objective.unit}
              <PelleAmount amount={tier.reward} className="font-semibold" kind="gold" signed />
            </li>
          ))}
        </ol>
      ) : null}
    </li>
  );
}

/** Story 41.16: every quest of the week done opens the chest - its reward, and how many quests are left. */
export function ChestRow({ chest, failed = false }: { chest: QuestChest; failed?: boolean }) {
  const open = chest.paid || (chest.done !== null && chest.done >= chest.total);

  return (
    <div className={`flex flex-wrap items-center gap-3 rounded-lg border px-3 py-2 text-sm ${open ? "border-warning/50 bg-warning/10" : "border-dashed border-border"}`}>
      <Gift aria-hidden className={`size-4 shrink-0 ${open ? "text-warning" : "text-muted-foreground"}`} />
      <span className="min-w-0 flex-1">
        <span className="font-medium text-foreground">Coffre de la semaine</span>
        <span className="block text-xs text-muted-foreground">
          {open ? (
            chest.paid ? (
              "Ouvert : toutes les quêtes sont faites."
            ) : (
              "Toutes les quêtes sont faites : crédité dans quelques minutes."
            )
          ) : chest.done === null ? (
            <>
              Fais toutes les quêtes pour l&apos;ouvrir ({" "}
              {failed ? "?" : <span aria-hidden className="inline-block h-3 w-4 animate-pulse rounded bg-surface-2 align-middle motion-reduce:animate-none" />} /{" "}
              {chest.total}).
            </>
          ) : (
            `Fais toutes les quêtes pour l'ouvrir (${chest.done} / ${chest.total}).`
          )}
        </span>
      </span>
      <PelleAmount amount={chest.reward} className="shrink-0 text-xs font-semibold" kind="gold" signed />
    </div>
  );
}

const historyDay = new Intl.DateTimeFormat("fr-FR", { day: "numeric", month: "short", timeZone: "Europe/Paris" });

/**
 * Story 41.17: the member's weeks before this one - each with the quests done out of those served, the chest, and
 * what they earned. Story 41.21: its own block under the quests in progress, open.
 */
export function QuestHistory({ weeks }: { weeks: QuestWeekHistory[] }) {
  const earned = weeks.reduce((sum, week) => sum + week.pelles, 0);

  return (
    <section aria-labelledby="quest-history" className="grid gap-3 rounded-xl border border-border p-5">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="font-heading text-xl font-semibold text-foreground" id="quest-history">
          Historique des quêtes
        </h2>
        <p className="text-xs text-muted-foreground">
          {weeks.length} dernières semaines · <PelleAmount amount={earned} className="font-semibold" kind="gold" />
        </p>
      </div>
      <ul className="divide-y divide-border" role="list">
        {weeks.map((week) => (
          <li className="flex flex-wrap items-center gap-x-3 gap-y-1 py-2 text-sm" key={week.week}>
            <span className="w-32 shrink-0 whitespace-nowrap text-muted-foreground">Sem. du {historyDay.format(new Date(week.startsAt))}</span>
            <span className={`tabular-nums ${week.served > 0 && week.done >= week.served ? "text-success" : "text-foreground"}`}>
              {week.served === 0 ? "Pas de quête" : `${week.done} / ${week.served} quête${week.served > 1 ? "s" : ""}`}
            </span>
            {week.chest ? (
              <span className="inline-flex items-center gap-1 text-xs text-warning">
                <Gift aria-hidden className="size-3.5" />
                coffre ouvert
              </span>
            ) : null}
            <PelleAmount amount={week.pelles} className="ml-auto font-semibold" kind="gold" signed />
          </li>
        ))}
      </ul>
    </section>
  );
}
