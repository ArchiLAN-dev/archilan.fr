import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

export type AdminPostType = "news" | "recap" | "announcement";

// Shape returned by the list endpoint (content dashboard).
export type AdminPostSummary = {
  id: string;
  slug: string;
  title: string;
  type: AdminPostType;
  status: "draft" | "published";
  excerpt: string;
  body: string[];
  readingTime: string;
  coverImageUrl: string | null;
  publishedAt: string | null;
  createdAt: string;
  updatedAt: string;
};

// Shape returned by the single-post endpoint (edit form).
export type AdminPostDetail = {
  id: string;
  slug: string;
  title: string;
  type: AdminPostType;
  status: "draft" | "published";
  excerpt: string;
  body: string[];
  readingTime: string;
  coverImageUrl: string | null;
  coverImageKey: string | null;
};

// Discriminated result: 401/403 keep the dashboard's dedicated "denied" screen, other failures
// keep their distinct French messages. Never throws (AC-API2) - the old effect was one-shot too.
export type AdminPostsResult =
  | { kind: "ready"; posts: AdminPostSummary[] }
  | { kind: "denied"; message: string }
  | { kind: "error"; message: string };

// `post: null` = 2xx with an unexpected payload: the old effect silently left the form empty
// in that case, without surfacing an error.
export type AdminPostResult =
  | { kind: "ready"; post: AdminPostDetail | null }
  | { kind: "error"; message: string };

export type TogglePublishResult = { ok: true } | { ok: false; message: string };

export async function fetchAdminPosts(): Promise<AdminPostsResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/posts`);

    if (res.status === 401 || res.status === 403) {
      return { kind: "denied", message: "Accès réservé aux admins ArchiLAN." };
    }
    if (!res.ok) {
      return { kind: "error", message: "Impossible de charger les articles." };
    }

    const payload: unknown = await res.json();
    return { kind: "ready", posts: isPostListPayload(payload) ? payload.data : [] };
  } catch {
    return { kind: "error", message: "Impossible de contacter l'API." };
  }
}

export async function fetchAdminPost(postId: string): Promise<AdminPostResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/posts/${postId}`);
    if (!res.ok) {
      return { kind: "error", message: "Article introuvable ou accès refusé." };
    }
    const payload: unknown = await res.json();
    return { kind: "ready", post: isPostPayload(payload) ? payload.data : null };
  } catch {
    return { kind: "error", message: "Impossible de charger l'article." };
  }
}

export async function togglePostPublish(
  postId: string,
  action: "publish" | "unpublish",
): Promise<TogglePublishResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/posts/${postId}/${action}`, {
      method: "POST",
    });
    if (!res.ok) {
      return { ok: false, message: "L'action a échoué. Veuillez réessayer." };
    }
    return { ok: true };
  } catch {
    return { ok: false, message: "Impossible de contacter l'API." };
  }
}

function isPostListPayload(payload: unknown): payload is { data: AdminPostSummary[] } {
  if (typeof payload !== "object" || payload === null || !("data" in payload)) return false;
  return Array.isArray(payload.data);
}

function isPostPayload(payload: unknown): payload is { data: AdminPostDetail } {
  if (typeof payload !== "object" || payload === null || !("data" in payload)) return false;
  const data: unknown = payload.data;
  if (typeof data !== "object" || data === null) return false;
  return "id" in data && "slug" in data;
}
