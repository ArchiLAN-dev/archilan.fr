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
  /** Story 39.16: the game to link to (null when unlisted), and the decision once taken. Absent on an older API. */
  gameId?: string | null;
  reviewedAt?: string | null;
  rejectionReason?: string | null;
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
  /** Story 11.7: only this game's contributions (its editor's « Tutoriel » tab). */
  gameId?: string;
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
  if (filters.gameId !== undefined) params.set("game", filters.gameId);
  return params.toString();
}

/** Story 39.16: one contribution, for its own moderation page. */
export type ContributionResult = { kind: "ready"; item: ContributionItem } | { kind: "not_found" } | { kind: "error" };

export async function fetchContribution(id: string): Promise<ContributionResult> {
  try {
    const response = await apiFetch(`${env.apiBaseUrl}/admin/game-contributions/${id}`);
    if (response.status === 404) return { kind: "not_found" };
    if (!response.ok) return { kind: "error" };
    const payload: unknown = await response.json();
    return typeof payload === "object" && payload !== null && "data" in payload && isContributionItem(payload.data)
      ? { kind: "ready", item: payload.data }
      : { kind: "error" };
  } catch {
    return { kind: "error" };
  }
}

export type StepChange = "same" | "modified" | "added" | "removed";
export type StepComparison = { position: number; change: StepChange; current: GameStep | null; proposed: GameStep | null };

function sameStep(a: GameStep, b: GameStep): boolean {
  return a.type === b.type && a.title === b.title && a.description === b.description && (a.videoUrl ?? null) === (b.videoUrl ?? null);
}

/**
 * Story 39.16: the current tutorial against the proposed one, position by position - an approval replaces the whole
 * tutorial, so what each position becomes is what the moderator checks.
 */
export function compareSteps(current: GameStep[], proposed: GameStep[]): StepComparison[] {
  return Array.from({ length: Math.max(current.length, proposed.length) }, (_, index) => {
    const before = current[index] ?? null;
    const after = proposed[index] ?? null;
    const change: StepChange = before === null ? "added" : after === null ? "removed" : sameStep(before, after) ? "same" : "modified";
    return { position: index + 1, change, current: before, proposed: after };
  });
}

/** How many steps changed, by kind - the summary at the top of the comparison. */
export function summarizeChanges(rows: StepComparison[]): Record<StepChange, number> {
  const counts: Record<StepChange, number> = { same: 0, modified: 0, added: 0, removed: 0 };
  for (const row of rows) counts[row.change] += 1;
  return counts;
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

/** Story 41.5: `pelles` are the gold pelles paid to the author, 0 for none. */
export async function approveContribution(id: string, pelles = 0): Promise<boolean> {
  try {
    const response = await apiFetch(`${env.apiBaseUrl}/admin/game-contributions/${id}/approve`, {
      body: JSON.stringify({ pelles }),
      headers: { "Content-Type": "application/json" },
      method: "POST",
    });
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
