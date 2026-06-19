import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasNumberProp, hasStringProp, hasNullableStringProp } from "@/lib/type-guards";
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

export type ContributionStatus = "pending" | "approved" | "rejected" | "all";
export type ContributionTarget = "any" | "listed" | "unlisted";
export type ContributionSort = "recent" | "oldest";

export type ContributionFilters = {
  status: ContributionStatus;
  target: ContributionTarget;
  sort: ContributionSort;
  search: string;
};

export const DEFAULT_CONTRIBUTION_FILTERS: ContributionFilters = {
  status: "pending",
  target: "any",
  sort: "recent",
  search: "",
};

export type ContributionQueue = { items: ContributionItem[]; count: number };

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

export function buildContributionsQuery(filters: ContributionFilters): string {
  const params = new URLSearchParams();
  params.set("status", filters.status);
  params.set("sort", filters.sort);
  if (filters.target !== "any") params.set("target", filters.target);
  const q = filters.search.trim();
  if (q !== "") params.set("q", q);
  return params.toString();
}

export async function fetchContributionQueue(
  filters: ContributionFilters = DEFAULT_CONTRIBUTION_FILTERS,
): Promise<ContributionQueue> {
  try {
    const response = await apiFetch(`${env.apiBaseUrl}/admin/game-contributions?${buildContributionsQuery(filters)}`);
    if (!response.ok) return { items: [], count: 0 };

    const payload: unknown = await response.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload)) return { items: [], count: 0 };
    if (!Array.isArray(payload.data) || !payload.data.every(isContributionItem)) return { items: [], count: 0 };

    let count = payload.data.length;
    const meta: unknown = "meta" in payload ? payload.meta : null;
    if (typeof meta === "object" && meta !== null && hasNumberProp(meta, "count")) {
      count = meta.count;
    }

    return { items: payload.data, count };
  } catch {
    return { items: [], count: 0 };
  }
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
