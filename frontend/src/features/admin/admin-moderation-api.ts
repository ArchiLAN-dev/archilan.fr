import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

export type ModerationActor = { slug: string; displayName: string | null; avatarUrl: string | null };

export type ModerationComment = {
  id: string;
  body: string;
  hidden: boolean;
  createdAt: string;
  author: ModerationActor | null;
  profileSlug: string | null;
};

export type ModerationReport = {
  id: string;
  targetType: string;
  targetId: string;
  reason: string;
  createdAt: string;
  category: string;
  problem: string;
  note: string | null;
  severity: number;
  uncategorized: boolean;
  reporter: ModerationActor | null;
  comment: ModerationComment | null;
  profile: ModerationActor | null;
};

/** An account whose unresolved profile reports cross the escalation threshold (story 30.28). */
export type FlaggedAccount = {
  userId: string;
  slug: string | null;
  displayName: string | null;
  avatarUrl: string | null;
  score: number;
  reportCount: number;
};

export type ModerationQueue = {
  reports: ModerationReport[];
  count: number;
  threshold: number;
  flagged: FlaggedAccount[];
};

export type ReportStatus = "pending" | "resolved" | "all";
export type ReportCommentState = "any" | "hidden" | "visible";
export type ReportTargetType = "any" | "comment" | "profile";
export type ReportProblem = "any" | "nudity" | "violence" | "hate" | "harassment" | "spam" | "other";
export type ReportSort = "recent" | "oldest" | "severity";

export type ReportFilters = {
  status: ReportStatus;
  commentState: ReportCommentState;
  targetType: ReportTargetType;
  problem: ReportProblem;
  uncategorized: boolean;
  sort: ReportSort;
  search: string;
};

export const DEFAULT_REPORT_FILTERS: ReportFilters = {
  status: "pending",
  commentState: "any",
  targetType: "any",
  problem: "any",
  uncategorized: false,
  sort: "severity",
  search: "",
};

/** Severity-driven label for a problem, shown as a chip on each report. */
export const PROBLEM_LABELS: Record<string, string> = {
  nudity: "Nudité",
  violence: "Violence",
  hate: "Haine",
  harassment: "Harcèlement",
  spam: "Spam",
  other: "Autre",
};

export const CATEGORY_LABELS: Record<string, string> = {
  avatar: "Photo de profil",
  display_name: "Pseudo",
  bio: "Biographie",
  social_link: "Lien externe",
  comment: "Commentaire",
  other: "Autre",
};

function isActor(v: unknown): v is ModerationActor {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "slug") &&
    hasNullableStringProp(v, "displayName") &&
    hasNullableStringProp(v, "avatarUrl")
  );
}

function isNullableActor(v: unknown): v is ModerationActor | null {
  return v === null || isActor(v);
}

function isComment(v: unknown): v is ModerationComment {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "id") || !hasStringProp(v, "body") || !hasStringProp(v, "createdAt")) return false;
  if (!hasBooleanProp(v, "hidden")) return false;
  if (!("author" in v) || !isNullableActor(v.author)) return false;
  return hasNullableStringProp(v, "profileSlug");
}

function isReport(v: unknown): v is ModerationReport {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "id") || !hasStringProp(v, "targetType") || !hasStringProp(v, "targetId")) return false;
  if (!hasStringProp(v, "reason") || !hasStringProp(v, "createdAt")) return false;
  if (!hasStringProp(v, "category") || !hasStringProp(v, "problem")) return false;
  if (!hasNullableStringProp(v, "note") || !hasNumberProp(v, "severity") || !hasBooleanProp(v, "uncategorized")) return false;
  if (!("reporter" in v) || !isNullableActor(v.reporter)) return false;
  if (!("comment" in v) || (v.comment !== null && !isComment(v.comment))) return false;
  return "profile" in v && isNullableActor(v.profile);
}

function isFlaggedAccount(v: unknown): v is FlaggedAccount {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "userId") &&
    hasNullableStringProp(v, "slug") &&
    hasNullableStringProp(v, "displayName") &&
    hasNullableStringProp(v, "avatarUrl") &&
    hasNumberProp(v, "score") &&
    hasNumberProp(v, "reportCount")
  );
}

export function buildReportsQuery(filters: ReportFilters): string {
  const params = new URLSearchParams();
  params.set("status", filters.status);
  params.set("sort", filters.sort);
  if (filters.commentState !== "any") params.set("commentState", filters.commentState);
  if (filters.targetType !== "any") params.set("targetType", filters.targetType);
  if (filters.problem !== "any") params.set("problem", filters.problem);
  if (filters.uncategorized) params.set("uncategorized", "1");
  const q = filters.search.trim();
  if (q !== "") params.set("q", q);
  return params.toString();
}

export async function fetchModerationQueue(
  filters: ReportFilters = DEFAULT_REPORT_FILTERS,
): Promise<ModerationQueue | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/community/reports?${buildReportsQuery(filters)}`);
    if (!res.ok) return null;
    const json: unknown = await res.json();
    if (typeof json !== "object" || json === null || !("data" in json) || !Array.isArray(json.data)) return null;
    if (!json.data.every(isReport)) return null;

    let count = json.data.length;
    let threshold = 0;
    let flagged: FlaggedAccount[] = [];
    const meta: unknown = "meta" in json ? json.meta : null;
    if (typeof meta === "object" && meta !== null) {
      if (hasNumberProp(meta, "count")) count = meta.count;
      if (hasNumberProp(meta, "threshold")) threshold = meta.threshold;
      if ("flagged" in meta && Array.isArray(meta.flagged) && meta.flagged.every(isFlaggedAccount)) {
        flagged = meta.flagged;
      }
    }

    return { reports: json.data, count, threshold, flagged };
  } catch {
    return null;
  }
}

async function post(path: string, body?: unknown): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}${path}`, {
      method: "POST",
      ...(body === undefined ? {} : { headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) }),
    });
    return res.ok;
  } catch {
    return false;
  }
}

export function hideModerationComment(commentId: string): Promise<boolean> {
  return post(`/admin/community/comments/${encodeURIComponent(commentId)}/hide`);
}

export function restoreModerationComment(commentId: string): Promise<boolean> {
  return post(`/admin/community/comments/${encodeURIComponent(commentId)}/restore`);
}

export function resolveModerationReport(reportId: string): Promise<boolean> {
  return post(`/admin/community/reports/${encodeURIComponent(reportId)}/resolve`);
}

// ── Account moderation actions (story 30.29) ──

export type ModerationActionKind = "warn" | "suspend" | "ban" | "lift";

export type AccountActionEntry = {
  id: string;
  action: string;
  reason: string;
  createdAt: string;
  actorId: string;
  relatedReportId: string | null;
};

const accountBase = (userId: string) => `/admin/community/accounts/${encodeURIComponent(userId)}`;

export function warnAccount(userId: string, reason: string): Promise<boolean> {
  return post(`${accountBase(userId)}/warn`, { reason });
}

export function suspendAccount(userId: string, until: string, reason: string): Promise<boolean> {
  return post(`${accountBase(userId)}/suspend`, { until, reason });
}

export function banAccount(userId: string, reason: string): Promise<boolean> {
  return post(`${accountBase(userId)}/ban`, { reason });
}

export function liftAccount(userId: string, reason: string): Promise<boolean> {
  return post(`${accountBase(userId)}/lift`, { reason });
}

export async function fetchAccountActions(userId: string): Promise<AccountActionEntry[]> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}${accountBase(userId)}/actions`);
    if (!res.ok) return [];
    const json: unknown = await res.json();
    if (typeof json !== "object" || json === null || !("data" in json) || !Array.isArray(json.data)) return [];
    return json.data.filter(
      (v): v is AccountActionEntry =>
        typeof v === "object" &&
        v !== null &&
        hasStringProp(v, "id") &&
        hasStringProp(v, "action") &&
        hasStringProp(v, "reason") &&
        hasStringProp(v, "createdAt") &&
        hasStringProp(v, "actorId") &&
        hasNullableStringProp(v, "relatedReportId"),
    );
  } catch {
    return [];
  }
}
