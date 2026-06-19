import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasStringProp, hasNullableStringProp } from "@/lib/type-guards";
import { isGameStep, type GameStep } from "@/features/games/public-games-api";

export type ContributionItem = {
  id: string;
  status: string;
  createdAt: string;
  authorName: string;
  message: string | null;
  target: string;
  gameSlug: string | null;
  proposedSteps: GameStep[];
  currentSteps: GameStep[];
};

function isStepArray(v: unknown): v is GameStep[] {
  return Array.isArray(v) && v.every(isGameStep);
}

function isContributionItem(v: unknown): v is ContributionItem {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "id") || !hasStringProp(v, "status") || !hasStringProp(v, "createdAt")) return false;
  if (!hasStringProp(v, "authorName") || !hasStringProp(v, "target")) return false;
  if (!hasNullableStringProp(v, "message") || !hasNullableStringProp(v, "gameSlug")) return false;
  if (!("proposedSteps" in v) || !isStepArray(v.proposedSteps)) return false;
  return "currentSteps" in v && isStepArray(v.currentSteps);
}

export async function fetchContributionQueue(): Promise<ContributionItem[]> {
  const response = await apiFetch(`${env.apiBaseUrl}/admin/game-contributions`);
  if (!response.ok) return [];

  const payload: unknown = await response.json();
  if (typeof payload !== "object" || payload === null || !("data" in payload)) return [];
  if (!Array.isArray(payload.data) || !payload.data.every(isContributionItem)) return [];

  return payload.data;
}

export async function approveContribution(id: string): Promise<boolean> {
  try {
    const response = await apiFetch(`${env.apiBaseUrl}/admin/game-contributions/${id}/approve`, { method: "POST" });
    return response.ok;
  } catch {
    return false;
  }
}

export async function rejectContribution(id: string, reason: string): Promise<boolean> {
  try {
    const response = await apiFetch(`${env.apiBaseUrl}/admin/game-contributions/${id}/reject`, {
      body: JSON.stringify({ reason }),
      headers: { "Content-Type": "application/json" },
      method: "POST",
    });
    return response.ok;
  } catch {
    return false;
  }
}
