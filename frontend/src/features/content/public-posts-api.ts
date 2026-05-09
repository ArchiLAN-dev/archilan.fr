import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import type { PublicPost } from "./content-types";
import { publicPosts, getPublicPostBySlug } from "./mock-posts";

type PostPayload = {
  slug: string;
  title: string;
  type: string;
  status: string;
  excerpt: string;
  coverImageUrl: string | null;
  body: string[];
  readingTime: string;
  publishedAt: string;
  relatedEventSlug: string | null;
  vodUrl: string | null;
};

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
    type: post.type as PublicPost["type"],
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
  return Boolean(
    payload &&
    typeof payload === "object" &&
    "data" in payload &&
    Array.isArray((payload as { data: unknown }).data),
  );
}

function isPostPayload(payload: unknown): payload is { data: PostPayload } {
  const data =
    payload && typeof payload === "object" && "data" in payload
      ? (payload as { data: unknown }).data
      : null;

  return Boolean(data && typeof data === "object" && "slug" in data && "title" in data);
}
