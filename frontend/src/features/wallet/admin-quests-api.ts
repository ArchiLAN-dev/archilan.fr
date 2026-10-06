import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

/** Story 41.15: an objective type of the catalog (code defined, like the facts of the achievements). */
export type QuestMetricOption = { key: string; label: string; unit: string; unitOne: string };

export type QuestObjectiveTerms = { metric: string; target: number };

export type AdminQuest = {
  id: string;
  title: string;
  description: string;
  reward: number;
  objectives: QuestObjectiveTerms[];
  inDraw: boolean;
  retired: boolean;
  createdAt: string;
  /** Story 41.16: how the quest did. */
  stats: QuestStats;
};

export type QuestStats = { weeksServed: number; lastWeek: string | null; lastMembers: number; pelles: number };

export type QuestWeekOrigin = "pinned" | "drawn";

export type ServedQuest = { questId: string; title: string; reward: number; origin: QuestWeekOrigin; retired: boolean };

export type AdminQuestWeek = { key: string; startsAt: string; endsAt: string; current: boolean; drawn: boolean; quests: ServedQuest[] };

/** Story 41.16: a week just past - what it served, who did each quest, the chests and the pelles paid. */
export type PastQuestWeek = {
  key: string;
  startsAt: string;
  endsAt: string;
  quests: (ServedQuest & { members: number; pelles: number })[];
  chests: number;
  pelles: number;
};

export type AdminQuests = {
  questsPerWeek: number;
  chestReward: number;
  metrics: QuestMetricOption[];
  quests: AdminQuest[];
  weeks: AdminQuestWeek[];
  pastWeeks: PastQuestWeek[];
};

export type QuestTerms = { title: string; description: string; reward: number; objectives: QuestObjectiveTerms[]; inDraw: boolean };

export const QUEST_LIMITS = { maxTitle: 80, maxDescription: 200, minReward: 1, maxReward: 1000, maxObjectives: 5, minTarget: 1, maxTarget: 10000, minPerWeek: 1, maxPerWeek: 10, maxChest: 1000 } as const;

function isObject(v: unknown): v is object {
  return typeof v === "object" && v !== null;
}

function isObjectiveTerms(v: unknown): v is QuestObjectiveTerms {
  return isObject(v) && hasStringProp(v, "metric") && hasNumberProp(v, "target");
}

function isMetric(v: unknown): v is QuestMetricOption {
  return isObject(v) && hasStringProp(v, "key") && hasStringProp(v, "label") && hasStringProp(v, "unit") && hasStringProp(v, "unitOne");
}

function isQuest(v: unknown): v is AdminQuest {
  return (
    isObject(v) &&
    hasStringProp(v, "id") &&
    hasStringProp(v, "title") &&
    hasStringProp(v, "description") &&
    hasNumberProp(v, "reward") &&
    hasBooleanProp(v, "inDraw") &&
    hasBooleanProp(v, "retired") &&
    hasStringProp(v, "createdAt") &&
    "stats" in v &&
    isStats(v.stats) &&
    "objectives" in v &&
    Array.isArray(v.objectives) &&
    v.objectives.every(isObjectiveTerms)
  );
}

function isStats(v: unknown): v is QuestStats {
  return (
    isObject(v) &&
    hasNumberProp(v, "weeksServed") &&
    hasNullableStringProp(v, "lastWeek") &&
    hasNumberProp(v, "lastMembers") &&
    hasNumberProp(v, "pelles")
  );
}

function isPastServed(v: unknown): v is PastQuestWeek["quests"][number] {
  return isServed(v) && hasNumberProp(v, "members") && hasNumberProp(v, "pelles");
}

function isPastWeek(v: unknown): v is PastQuestWeek {
  return (
    isObject(v) &&
    hasStringProp(v, "key") &&
    hasStringProp(v, "startsAt") &&
    hasStringProp(v, "endsAt") &&
    hasNumberProp(v, "chests") &&
    hasNumberProp(v, "pelles") &&
    "quests" in v &&
    Array.isArray(v.quests) &&
    v.quests.every(isPastServed)
  );
}

function isServed(v: unknown): v is ServedQuest {
  return (
    isObject(v) &&
    hasStringProp(v, "questId") &&
    hasStringProp(v, "title") &&
    hasNumberProp(v, "reward") &&
    hasBooleanProp(v, "retired") &&
    "origin" in v &&
    (v.origin === "pinned" || v.origin === "drawn")
  );
}

function isWeek(v: unknown): v is AdminQuestWeek {
  return (
    isObject(v) &&
    hasStringProp(v, "key") &&
    hasStringProp(v, "startsAt") &&
    hasStringProp(v, "endsAt") &&
    hasBooleanProp(v, "current") &&
    hasBooleanProp(v, "drawn") &&
    "quests" in v &&
    Array.isArray(v.quests) &&
    v.quests.every(isServed)
  );
}

export function isAdminQuests(v: unknown): v is AdminQuests {
  return (
    isObject(v) &&
    hasNumberProp(v, "questsPerWeek") &&
    hasNumberProp(v, "chestReward") &&
    "metrics" in v &&
    Array.isArray(v.metrics) &&
    v.metrics.every(isMetric) &&
    "quests" in v &&
    Array.isArray(v.quests) &&
    v.quests.every(isQuest) &&
    "weeks" in v &&
    Array.isArray(v.weeks) &&
    v.weeks.every(isWeek) &&
    "pastWeeks" in v &&
    Array.isArray(v.pastWeeks) &&
    v.pastWeeks.every(isPastWeek)
  );
}

export async function fetchAdminQuests(): Promise<AdminQuests | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/quests`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return isAdminQuests(payload) ? payload : null;
  } catch {
    return null;
  }
}

/** Each write answers null when done, or the API's message. */
async function send(url: string, init: RequestInit, expected: number, fallback: string): Promise<string | null> {
  try {
    const res = await apiFetch(url, init);
    if (res.status === expected) return null;
    const payload: unknown = await res.json().catch(() => null);
    if (isObject(payload) && "error" in payload && isObject(payload.error) && hasStringProp(payload.error, "message")) {
      return payload.error.message;
    }
    return fallback;
  } catch {
    return "Impossible de contacter l'API.";
  }
}

function json(method: string, body: unknown): RequestInit {
  return { method, headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) };
}

export function writeQuest(terms: QuestTerms): Promise<string | null> {
  return send(`${env.apiBaseUrl}/admin/quests`, json("POST", terms), 201, "Impossible de créer la quête.");
}

export function editQuest(questId: string, terms: QuestTerms): Promise<string | null> {
  return send(`${env.apiBaseUrl}/admin/quests/${encodeURIComponent(questId)}`, json("PATCH", terms), 204, "Impossible de modifier la quête.");
}

export function setQuestRetired(questId: string, retired: boolean): Promise<string | null> {
  return send(`${env.apiBaseUrl}/admin/quests/${encodeURIComponent(questId)}/${retired ? "retire" : "restore"}`, { method: "POST" }, 204, "Impossible de changer la quête.");
}

/** Story 41.16: the settings of the weeks, each optional - the quests a week, the chest for doing them all. */
export function setQuestSettings(settings: { questsPerWeek?: number; chestReward?: number }): Promise<string | null> {
  return send(`${env.apiBaseUrl}/admin/quests-settings`, json("PUT", settings), 204, "Impossible d'enregistrer le réglage.");
}

export function pinQuest(weekKey: string, questId: string, replaces: string | null = null): Promise<string | null> {
  return send(`${env.apiBaseUrl}/admin/quest-weeks/${encodeURIComponent(weekKey)}/quests`, json("POST", { questId, replaces }), 204, "Impossible d'épingler la quête.");
}

export function unpinQuest(weekKey: string, questId: string): Promise<string | null> {
  return send(
    `${env.apiBaseUrl}/admin/quest-weeks/${encodeURIComponent(weekKey)}/quests/${encodeURIComponent(questId)}`,
    { method: "DELETE" },
    204,
    "Impossible de retirer la quête de la semaine.",
  );
}

/** « 50 checks et 1 partie » - a quest's objectives in one line, singular for one. */
export function objectivesSummary(objectives: QuestObjectiveTerms[], metrics: QuestMetricOption[]): string {
  return objectives
    .map((objective) => {
      const metric = metrics.find((candidate) => candidate.key === objective.metric);
      const unit = metric ? (objective.target === 1 ? metric.unitOne : metric.unit) : objective.metric;
      return `${objective.target} ${unit}`;
    })
    .join(" et ");
}
