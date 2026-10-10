import {
  DEFAULT_CONTRIBUTION_FILTERS,
  type ContributionFilters,
  type ContributionSort,
  type ContributionStatus,
  type ContributionTarget,
} from "./admin-game-contributions-api";
import {
  DEFAULT_REPORT_FILTERS,
  type ReportCommentState,
  type ReportFilters,
  type ReportProblem,
  type ReportSort,
  type ReportStatus,
  type ReportTargetType,
} from "./admin-moderation-api";

/**
 * The moderation page's view in its address (story 39.12): tab, status, filters, sort and search. Defaults
 * are left out so the plain page has a plain URL; unknown values fall back to the default, so a stale or
 * hand-edited link still opens.
 */

export type Option<T extends string> = { value: T; label: string };

export type ModerationTab = "reports" | "contributions";

export const TAB_PARAM = "onglet";

const KEYS = {
  status: "statut",
  target: "cible",
  problem: "contenu",
  commentState: "commentaire",
  uncategorized: "noncat",
  sort: "tri",
  search: "q",
} as const;

export const REPORT_STATUS_OPTIONS: Option<ReportStatus>[] = [
  { value: "pending", label: "En attente" },
  { value: "resolved", label: "Résolus" },
  { value: "all", label: "Tous" },
];

export const REPORT_TARGET_OPTIONS: Option<ReportTargetType>[] = [
  { value: "any", label: "Tous" },
  { value: "comment", label: "Commentaires" },
  { value: "profile", label: "Profils" },
  { value: "run_listing", label: "Annonces" },
];

export const REPORT_PROBLEM_OPTIONS: Option<ReportProblem>[] = [
  { value: "any", label: "Tous" },
  { value: "nudity", label: "Nudité" },
  { value: "violence", label: "Violence" },
  { value: "hate", label: "Haine" },
  { value: "harassment", label: "Harcèlement" },
  { value: "spam", label: "Spam" },
  { value: "other", label: "Autre" },
];

export const REPORT_COMMENT_OPTIONS: Option<ReportCommentState>[] = [
  { value: "any", label: "Tous" },
  { value: "hidden", label: "Masqués" },
  { value: "visible", label: "Visibles" },
];

export const REPORT_SORT_OPTIONS: Option<ReportSort>[] = [
  { value: "severity", label: "Gravité" },
  { value: "recent", label: "Plus récents" },
  { value: "oldest", label: "Plus anciens" },
];

export const CONTRIBUTION_STATUS_OPTIONS: Option<ContributionStatus>[] = [
  { value: "pending", label: "En attente" },
  { value: "approved", label: "Approuvées" },
  { value: "rejected", label: "Rejetées" },
  { value: "all", label: "Toutes" },
];

export const CONTRIBUTION_TARGET_OPTIONS: Option<ContributionTarget>[] = [
  { value: "any", label: "Toutes" },
  { value: "listed", label: "Jeux listés" },
  { value: "unlisted", label: "Jeux non listés" },
];

export const CONTRIBUTION_SORT_OPTIONS: Option<ContributionSort>[] = [
  { value: "recent", label: "Plus récentes" },
  { value: "oldest", label: "Plus anciennes" },
];

function pick<T extends string>(params: URLSearchParams, key: string, options: Option<T>[], fallback: T): T {
  const raw = params.get(key);
  return options.find((option) => option.value === raw)?.value ?? fallback;
}

function labelOf<T extends string>(options: Option<T>[], value: T): string {
  return options.find((option) => option.value === value)?.label ?? value;
}

function setIfNotDefault(params: URLSearchParams, key: string, value: string, fallback: string): void {
  if (value !== fallback) params.set(key, value);
}

export function moderationTabFromParams(params: URLSearchParams): ModerationTab {
  return params.get(TAB_PARAM) === "contributions" ? "contributions" : "reports";
}

export const MODERATION_PATHS: Record<ModerationTab, string> = {
  reports: "/admin/moderation/signalements",
  contributions: "/admin/moderation/contributions",
};

/**
 * Story 39.13: the queues have their own pages. The old single page (`/admin/moderation?onglet=...`) sends
 * each link to the page of its tab, with its filters.
 */
export function moderationPathFor(params: URLSearchParams): string {
  const next = new URLSearchParams(params.toString());
  const path = MODERATION_PATHS[moderationTabFromParams(next)];
  next.delete(TAB_PARAM);
  const query = next.toString();
  return query === "" ? path : `${path}?${query}`;
}

// ── Reports ──

export function reportFiltersFromParams(params: URLSearchParams): ReportFilters {
  const d = DEFAULT_REPORT_FILTERS;
  return {
    status: pick(params, KEYS.status, REPORT_STATUS_OPTIONS, d.status),
    commentState: pick(params, KEYS.commentState, REPORT_COMMENT_OPTIONS, d.commentState),
    targetType: pick(params, KEYS.target, REPORT_TARGET_OPTIONS, d.targetType),
    problem: pick(params, KEYS.problem, REPORT_PROBLEM_OPTIONS, d.problem),
    uncategorized: params.get(KEYS.uncategorized) === "1",
    sort: pick(params, KEYS.sort, REPORT_SORT_OPTIONS, d.sort),
    search: (params.get(KEYS.search) ?? "").trim(),
  };
}

export function reportFiltersToParams(filters: ReportFilters): URLSearchParams {
  const d = DEFAULT_REPORT_FILTERS;
  const params = new URLSearchParams();
  setIfNotDefault(params, KEYS.status, filters.status, d.status);
  setIfNotDefault(params, KEYS.target, filters.targetType, d.targetType);
  setIfNotDefault(params, KEYS.problem, filters.problem, d.problem);
  setIfNotDefault(params, KEYS.commentState, filters.commentState, d.commentState);
  if (filters.uncategorized) params.set(KEYS.uncategorized, "1");
  setIfNotDefault(params, KEYS.sort, filters.sort, d.sort);
  const search = filters.search.trim();
  if (search !== "") params.set(KEYS.search, search);
  return params;
}

export type ReportChipKey = "targetType" | "problem" | "commentState" | "uncategorized" | "search";
export type Chip<K extends string> = { key: K; label: string };

export function reportChips(filters: ReportFilters): Chip<ReportChipKey>[] {
  const chips: Chip<ReportChipKey>[] = [];
  if (filters.targetType !== "any") chips.push({ key: "targetType", label: labelOf(REPORT_TARGET_OPTIONS, filters.targetType) });
  if (filters.problem !== "any") chips.push({ key: "problem", label: labelOf(REPORT_PROBLEM_OPTIONS, filters.problem) });
  if (filters.commentState !== "any") {
    chips.push({ key: "commentState", label: `Commentaires ${labelOf(REPORT_COMMENT_OPTIONS, filters.commentState).toLowerCase()}` });
  }
  if (filters.uncategorized) chips.push({ key: "uncategorized", label: "Non catégorisés" });
  if (filters.search.trim() !== "") chips.push({ key: "search", label: `« ${filters.search.trim()} »` });
  return chips;
}

/** Anything narrowing the list, status included (story 39.13); the sort only orders it. */
export function reportFiltersActive(filters: ReportFilters): boolean {
  return filters.status !== DEFAULT_REPORT_FILTERS.status || reportChips(filters).length > 0;
}

/** Back to the default view; the sort is kept, it narrows nothing. */
export function clearedReportFilters(filters: ReportFilters): ReportFilters {
  return { ...DEFAULT_REPORT_FILTERS, sort: filters.sort };
}

// ── Contributions ──

export function contributionFiltersFromParams(params: URLSearchParams): ContributionFilters {
  const d = DEFAULT_CONTRIBUTION_FILTERS;
  return {
    status: pick(params, KEYS.status, CONTRIBUTION_STATUS_OPTIONS, d.status),
    target: pick(params, KEYS.target, CONTRIBUTION_TARGET_OPTIONS, d.target),
    sort: pick(params, KEYS.sort, CONTRIBUTION_SORT_OPTIONS, d.sort),
    search: (params.get(KEYS.search) ?? "").trim(),
  };
}

export function contributionFiltersToParams(filters: ContributionFilters): URLSearchParams {
  const d = DEFAULT_CONTRIBUTION_FILTERS;
  const params = new URLSearchParams();
  setIfNotDefault(params, KEYS.status, filters.status, d.status);
  setIfNotDefault(params, KEYS.target, filters.target, d.target);
  setIfNotDefault(params, KEYS.sort, filters.sort, d.sort);
  const search = filters.search.trim();
  if (search !== "") params.set(KEYS.search, search);
  return params;
}

export type ContributionChipKey = "target" | "search";

export function contributionChips(filters: ContributionFilters): Chip<ContributionChipKey>[] {
  const chips: Chip<ContributionChipKey>[] = [];
  if (filters.target !== "any") chips.push({ key: "target", label: labelOf(CONTRIBUTION_TARGET_OPTIONS, filters.target) });
  if (filters.search.trim() !== "") chips.push({ key: "search", label: `« ${filters.search.trim()} »` });
  return chips;
}

export function contributionFiltersActive(filters: ContributionFilters): boolean {
  return filters.status !== DEFAULT_CONTRIBUTION_FILTERS.status || contributionChips(filters).length > 0;
}

export function clearedContributionFilters(filters: ContributionFilters): ContributionFilters {
  return { ...DEFAULT_CONTRIBUTION_FILTERS, sort: filters.sort };
}
