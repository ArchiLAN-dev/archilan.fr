import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

/** Story 30.53: one condition of an achievement's rule, with where the member stands. */
export type ProgressCondition = {
  type: "condition";
  fact: string;
  label: string;
  operator: string;
  value: number;
  value2: number | null;
  current: number;
  met: boolean;
};

export type ProgressGroup = { type: "group"; op: "all" | "any" | "none"; met: boolean; rules: ProgressNode[] };

export type ProgressNode = ProgressCondition | ProgressGroup;

export type AchievementProgress = {
  key: string;
  unlocked: boolean;
  unlockedAt: string | null;
  /** Given by the team rather than earned by the rule. */
  byTeam: boolean;
  /** The rule, node by node; null once unlocked. */
  progress: ProgressGroup | null;
};

const GROUP_OPS: readonly ProgressGroup["op"][] = ["all", "any", "none"];

export function isProgressNode(v: unknown): v is ProgressNode {
  if (typeof v !== "object" || v === null || !hasBooleanProp(v, "met")) return false;
  if ("type" in v && v.type === "group") {
    return "op" in v && GROUP_OPS.some((op) => op === v.op) && "rules" in v && Array.isArray(v.rules) && v.rules.every(isProgressNode);
  }
  return (
    "type" in v &&
    v.type === "condition" &&
    hasStringProp(v, "fact") &&
    hasStringProp(v, "label") &&
    hasStringProp(v, "operator") &&
    hasNumberProp(v, "value") &&
    hasNumberProp(v, "current") &&
    "value2" in v &&
    (v.value2 === null || typeof v.value2 === "number")
  );
}

function isAchievementProgress(v: unknown): v is AchievementProgress {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "key") &&
    hasBooleanProp(v, "unlocked") &&
    hasNullableStringProp(v, "unlockedAt") &&
    hasBooleanProp(v, "byTeam") &&
    "progress" in v &&
    (v.progress === null || (isProgressNode(v.progress) && v.progress.type === "group"))
  );
}

/** The signed-in member's progress on one of their achievements, computed on demand (one request per opening). */
export async function fetchAchievementProgress(key: string): Promise<AchievementProgress | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/profile/achievements/${encodeURIComponent(key)}/progress`);
    if (!res.ok) return null;
    const json: unknown = await res.json();
    const data: unknown = typeof json === "object" && json !== null && "data" in json ? json.data : null;
    return isAchievementProgress(data) ? data : null;
  } catch {
    return null;
  }
}
