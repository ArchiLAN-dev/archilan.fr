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
  reporter: ModerationActor | null;
  comment: ModerationComment | null;
  profile: ModerationActor | null;
};

export type ModerationQueue = { reports: ModerationReport[]; count: number };

export type ReportStatus = "pending" | "resolved" | "all";
export type ReportCommentState = "any" | "hidden" | "visible";
export type ReportTargetType = "any" | "comment" | "profile";
export type ReportSort = "recent" | "oldest";

export type ReportFilters = {
  status: ReportStatus;
  commentState: ReportCommentState;
  targetType: ReportTargetType;
  sort: ReportSort;
  search: string;
};

export const DEFAULT_REPORT_FILTERS: ReportFilters = {
  status: "pending",
  commentState: "any",
  targetType: "any",
  sort: "recent",
  search: "",
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
  if (!("reporter" in v) || !isNullableActor(v.reporter)) return false;
  if (!("comment" in v) || (v.comment !== null && !isComment(v.comment))) return false;
  return "profile" in v && isNullableActor(v.profile);
}

export function buildReportsQuery(filters: ReportFilters): string {
  const params = new URLSearchParams();
  params.set("status", filters.status);
  params.set("sort", filters.sort);
  if (filters.commentState !== "any") params.set("commentState", filters.commentState);
  if (filters.targetType !== "any") params.set("targetType", filters.targetType);
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
    const meta: unknown = "meta" in json ? json.meta : null;
    if (typeof meta === "object" && meta !== null && hasNumberProp(meta, "count")) {
      count = meta.count;
    }

    return { reports: json.data, count };
  } catch {
    return null;
  }
}

async function post(path: string): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}${path}`, { method: "POST" });
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
