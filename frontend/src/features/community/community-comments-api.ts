import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasStringProp } from "@/lib/type-guards";

export type CommentAuthor = { slug: string; displayName: string | null; avatarUrl: string | null };

export type ProfileComment = {
  id: string;
  body: string;
  createdAt: string;
  author: CommentAuthor | null;
  canDelete: boolean;
};

export type PostResult = "ok" | "rate_limited" | "forbidden" | "error";

function isAuthor(v: unknown): v is CommentAuthor {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "slug") &&
    hasNullableStringProp(v, "displayName") &&
    hasNullableStringProp(v, "avatarUrl")
  );
}

function isComment(v: unknown): v is ProfileComment {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "id") || !hasStringProp(v, "body") || !hasStringProp(v, "createdAt")) return false;
  if (!hasBooleanProp(v, "canDelete")) return false;
  return "author" in v && (v.author === null || isAuthor(v.author));
}

export async function fetchComments(slug: string): Promise<ProfileComment[] | "forbidden" | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/profiles/${encodeURIComponent(slug)}/comments`);
    if (res.status === 403) return "forbidden";
    if (!res.ok) return null;
    const json: unknown = await res.json();
    if (typeof json !== "object" || json === null || !("data" in json) || !Array.isArray(json.data)) return null;
    return json.data.every(isComment) ? json.data : null;
  } catch {
    return null;
  }
}

export async function postComment(slug: string, body: string): Promise<PostResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/profiles/${encodeURIComponent(slug)}/comments`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ body }),
    });
    if (res.status === 201) return "ok";
    if (res.status === 429) return "rate_limited";
    if (res.status === 401 || res.status === 403) return "forbidden";
    return "error";
  } catch {
    return "error";
  }
}

export async function deleteComment(id: string): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/comments/${encodeURIComponent(id)}`, { method: "DELETE" });
    return res.ok;
  } catch {
    return false;
  }
}

export async function reportComment(id: string, reason: string): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/comments/${encodeURIComponent(id)}/report`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ reason }),
    });
    return res.ok;
  } catch {
    return false;
  }
}
