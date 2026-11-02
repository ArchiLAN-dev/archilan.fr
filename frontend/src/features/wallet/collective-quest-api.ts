import { env } from "@/lib/env";
import { hasBooleanProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

/**
 * Story 41.69: the week's collective quest on the community page - the total the hourly run of the quests last
 * counted, no member named.
 */
export type CommunityCollectiveQuest = {
  title: string;
  description: string;
  /** « 500 checks », « 3 goals sur Hollow Knight ». */
  objective: string;
  reward: number;
  target: number;
  total: number;
  unit: string;
  duration: boolean;
  reached: boolean;
  renewsAt: string;
  countedAt: string;
};

export function isCommunityCollectiveQuest(v: unknown): v is CommunityCollectiveQuest {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "title") &&
    hasStringProp(v, "description") &&
    hasStringProp(v, "objective") &&
    hasNumberProp(v, "reward") &&
    hasNumberProp(v, "target") &&
    hasNumberProp(v, "total") &&
    hasStringProp(v, "unit") &&
    hasBooleanProp(v, "duration") &&
    hasBooleanProp(v, "reached") &&
    hasStringProp(v, "renewsAt") &&
    hasStringProp(v, "countedAt")
  );
}

/** Null when the week has none, nothing counted yet, or the API failed: the section hides. */
export async function fetchCommunityCollectiveQuest(): Promise<CommunityCollectiveQuest | null> {
  try {
    const response = await fetch(`${env.apiBaseUrl}/community/collective-quest`, { cache: "no-store" });
    if (!response.ok) return null;
    const payload: unknown = await response.json();
    const quest = typeof payload === "object" && payload !== null && "quest" in payload ? payload.quest : null;
    return isCommunityCollectiveQuest(quest) ? quest : null;
  } catch {
    return null;
  }
}
