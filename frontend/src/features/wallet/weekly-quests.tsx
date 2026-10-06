"use client";

import { useQuery } from "@tanstack/react-query";
import { CheckCircle2, Circle, Gift } from "lucide-react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { hasBooleanProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";
import { PelleAmount } from "./pelle-amount";

/** Story 41.15: one objective of a quest, and where the member stands on it this week. */
export type QuestObjectiveProgress = { metric: string; label: string; unit: string; target: number; current: number };

/** Story 41.6: one quest of the week. Story 41.15: written by the admins, with 1 to 5 objectives. */
export type WeeklyQuest = {
  key: string;
  label: string;
  description: string;
  reward: number;
  done: boolean;
  paid: boolean;
  objectives: QuestObjectiveProgress[];
};

/** Story 41.16: the chest for doing every quest of the week; null when there is none. */
export type QuestChest = { reward: number; done: number; total: number; paid: boolean };

export type WeeklyQuests = { week: string; renewsAt: string; quests: WeeklyQuest[]; chest: QuestChest | null };

function isChest(v: unknown): v is QuestChest {
  return typeof v === "object" && v !== null && hasNumberProp(v, "reward") && hasNumberProp(v, "done") && hasNumberProp(v, "total") && hasBooleanProp(v, "paid");
}

function isObjective(v: unknown): v is QuestObjectiveProgress {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "metric") &&
    hasStringProp(v, "label") &&
    hasStringProp(v, "unit") &&
    hasNumberProp(v, "target") &&
    hasNumberProp(v, "current")
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
    v.objectives.every(isObjective)
  );
}

export function isWeeklyQuests(v: unknown): v is WeeklyQuests {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "week") &&
    hasStringProp(v, "renewsAt") &&
    "quests" in v &&
    Array.isArray(v.quests) &&
    v.quests.every(isQuest) &&
    "chest" in v &&
    (v.chest === null || isChest(v.chest))
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

const renewFormatter = new Intl.DateTimeFormat("fr-FR", { weekday: "long", day: "numeric", month: "long", timeZone: "Europe/Paris" });

export function WeeklyQuestsPanel() {
  const { data } = useQuery({ queryKey: ["my-quests"], queryFn: fetchMyQuests, staleTime: DEFAULT_STALE_TIME, retry: false });

  return data ? <WeeklyQuestsView quests={data} /> : null;
}

/**
 * The quests of the week on « Mon portefeuille » (story 41.6): what each pays, done or not, paid or about to be.
 * Story 41.15: one progress bar per objective.
 */
export function WeeklyQuestsView({ quests }: { quests: WeeklyQuests }) {
  return (
    <section aria-labelledby="weekly-quests" className="grid gap-3 rounded-xl border border-border p-5">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="font-heading text-xl font-semibold text-foreground" id="weekly-quests">
          Quêtes de la semaine
        </h2>
        <p className="text-xs text-muted-foreground">Nouvelles quêtes le {renewFormatter.format(new Date(quests.renewsAt))}</p>
      </div>
      {quests.quests.length === 0 ? (
        <p className="text-sm text-muted-foreground">Pas de quête cette semaine.</p>
      ) : (
        <ul className="grid gap-4">
          {quests.quests.map((quest) => (
            <li className="grid gap-2" key={quest.key}>
              <div className="flex items-center justify-between gap-3 text-sm">
                <span className="flex min-w-0 items-center gap-2">
                  {quest.done ? (
                    <CheckCircle2 aria-hidden className="size-4 shrink-0 text-success" />
                  ) : (
                    <Circle aria-hidden className="size-4 shrink-0 text-muted-foreground" />
                  )}
                  <span className={`font-medium ${quest.done ? "text-foreground" : "text-muted-foreground"}`}>{quest.label}</span>
                  <span className="sr-only">{quest.done ? "faite" : "à faire"}</span>
                  {quest.done && !quest.paid ? <span className="text-xs text-muted-foreground">(créditée dans l&apos;heure)</span> : null}
                </span>
                <PelleAmount amount={quest.reward} className="shrink-0 text-xs font-semibold text-warning" signed />
              </div>
              {quest.description !== "" ? <p className="pl-6 text-xs text-muted-foreground">{quest.description}</p> : null}
              <ul className="grid gap-1.5 pl-6">
                {quest.objectives.map((objective) => (
                  <ObjectiveBar done={quest.done} key={objective.metric} objective={objective} />
                ))}
              </ul>
            </li>
          ))}
        </ul>
      )}
      {quests.chest ? <ChestRow chest={quests.chest} /> : null}
    </section>
  );
}

/** « 3 / 5 checks », capped at the target: a paid quest shows full even if the week's count is read later. */
export function ObjectiveBar({ objective, done }: { objective: QuestObjectiveProgress; done: boolean }) {
  const shown = done ? objective.target : Math.min(objective.current, objective.target);
  const percent = objective.target > 0 ? Math.round((shown / objective.target) * 100) : 0;
  const reached = shown >= objective.target;

  return (
    <li className="grid gap-1">
      <div className="flex items-baseline justify-between gap-2 text-xs">
        <span className="text-muted-foreground">{objective.label}</span>
        <span className={`tabular-nums ${reached ? "text-success" : "text-muted-foreground"}`}>
          {shown} / {objective.target} {objective.unit}
        </span>
      </div>
      <div
        aria-label={objective.label}
        aria-valuemax={objective.target}
        aria-valuemin={0}
        aria-valuenow={shown}
        className="h-1.5 overflow-hidden rounded-full bg-surface-2"
        role="progressbar"
      >
        <div className={`h-full rounded-full transition-[width] ${reached ? "bg-success" : "bg-accent"}`} style={{ width: `${percent}%` }} />
      </div>
    </li>
  );
}

/** Story 41.16: every quest of the week done opens the chest - its reward, and how many quests are left. */
export function ChestRow({ chest }: { chest: QuestChest }) {
  const open = chest.done >= chest.total;

  return (
    <div className={`flex flex-wrap items-center gap-3 rounded-lg border px-3 py-2 text-sm ${open ? "border-warning/50 bg-warning/10" : "border-dashed border-border"}`}>
      <Gift aria-hidden className={`size-4 shrink-0 ${open ? "text-warning" : "text-muted-foreground"}`} />
      <span className="min-w-0 flex-1">
        <span className="font-medium text-foreground">Coffre de la semaine</span>
        <span className="block text-xs text-muted-foreground">
          {open
            ? chest.paid
              ? "Ouvert : toutes les quêtes sont faites."
              : "Toutes les quêtes sont faites : crédité dans l'heure."
            : `Fais toutes les quêtes pour l'ouvrir (${chest.done} / ${chest.total}).`}
        </span>
      </span>
      <PelleAmount amount={chest.reward} className="shrink-0 text-xs font-semibold text-warning" signed />
    </div>
  );
}
