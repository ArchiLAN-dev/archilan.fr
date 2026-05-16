import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasStringProp } from "@/lib/type-guards";
import type { PublicPost, PublicPostType } from "./content-types";
import { publicPosts, getPublicPostBySlug } from "./mock-posts";

type PostPayload = {
  slug: string;
  title: string;
  type: PublicPostType;
  status: string;
  excerpt: string;
  coverImageUrl: string | null;
  body: string[];
  readingTime: string;
  publishedAt: string;
  relatedEventSlug: string | null;
  vodUrl: string | null;
};

function isPublicPostType(v: unknown): v is PublicPostType {
  return v === "news" || v === "recap" || v === "announcement";
}

export async function getPublicPosts(): Promise<PublicPost[]> {
  try {
    const response = await apiFetch(`${env.apiBaseUrl}/posts`, { cache: "no-store" });

    if (!response.ok) {
      return publicPosts;
    }

    const payload: unknown = await response.json();
    if (!isPostListPayload(payload)) {
      return publicPosts;
    }

    return payload.data.map(toPublicPost);
  } catch {
    return publicPosts;
  }
}

export async function getPublicPostBySlugFromApi(slug: string): Promise<PublicPost | null> {
  const fallback = getPublicPostBySlug(slug) ?? null;

  try {
    const response = await apiFetch(`${env.apiBaseUrl}/posts/${encodeURIComponent(slug)}`, {
      cache: "no-store",
    });

    if (!response.ok) {
      return fallback;
    }

    const payload: unknown = await response.json();
    if (!isPostPayload(payload)) {
      return fallback;
    }

    return toPublicPost(payload.data);
  } catch {
    return fallback;
  }
}

function toPublicPost(post: PostPayload): PublicPost {
  const publishedDate = new Date(post.publishedAt);

  return {
    slug: post.slug,
    title: post.title,
    type: post.type,
    status: "published",
    excerpt: post.excerpt,
    coverImageUrl: post.coverImageUrl ?? null,
    body: post.body,
    readingTime: post.readingTime,
    publishedAt: new Intl.DateTimeFormat("fr-FR", { dateStyle: "long" }).format(publishedDate),
    publishedAtIso: publishedDate.toISOString().slice(0, 10),
    ...(post.relatedEventSlug ? { relatedEventSlug: post.relatedEventSlug } : {}),
    ...(post.vodUrl ? { vodUrl: post.vodUrl } : {}),
  };
}

function isPostListPayload(payload: unknown): payload is { data: PostPayload[] } {
  if (typeof payload !== "object" || payload === null) return false;
  if (!("data" in payload)) return false;
  return Array.isArray(payload.data) && payload.data.every(isPostPayloadItem);
}

function isPostPayload(payload: unknown): payload is { data: PostPayload } {
  if (typeof payload !== "object" || payload === null) return false;
  if (!("data" in payload)) return false;
  const data = payload.data;
  if (typeof data !== "object" || data === null) return false;
  return isPostPayloadItem(data);
}

function isPostPayloadItem(v: unknown): v is PostPayload {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "slug")) return false;
  if (!hasStringProp(v, "title")) return false;
  if (!("type" in v) || !isPublicPostType(v.type)) return false;
  if (!hasStringProp(v, "status")) return false;
  if (!hasStringProp(v, "excerpt")) return false;
  if (!("coverImageUrl" in v) || (v.coverImageUrl !== null && typeof v.coverImageUrl !== "string")) return false;
  if (!("body" in v) || !Array.isArray(v.body) || !v.body.every((item): item is string => typeof item === "string")) {
    return false;
  }
  if (!hasStringProp(v, "readingTime")) return false;
  if (!hasStringProp(v, "publishedAt")) return false;
  if (!("relatedEventSlug" in v) || (v.relatedEventSlug !== null && typeof v.relatedEventSlug !== "string")) return false;
  return "vodUrl" in v && (v.vodUrl === null || typeof v.vodUrl === "string");
}
