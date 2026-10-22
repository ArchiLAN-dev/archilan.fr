import { createHash, timingSafeEqual } from "node:crypto";

/**
 * Story 34.10: what `POST /api/revalidate` accepts. Kept apart from the route handler so the rules are tested
 * without a Next.js server.
 */

export const MAX_TAGS = 20;

/** `events`, `event:<id>`, `posts`, `post:<slug>`: a kind, then an optional identifier. */
const TAG_PATTERN = /^[a-z]+(:[A-Za-z0-9_-]{1,120})?$/;

/** The tags of a request body, or null when the body is not `{ tags: [1 to 20 valid tags] }`. */
export function readTags(body: unknown): string[] | null {
  if (typeof body !== "object" || body === null || !("tags" in body)) return null;
  const tags: unknown = body.tags;
  if (!Array.isArray(tags) || tags.length === 0 || tags.length > MAX_TAGS) return null;
  if (!tags.every((tag): tag is string => typeof tag === "string" && TAG_PATTERN.test(tag))) return null;
  return [...new Set(tags)];
}

/**
 * Whether an `Authorization: Bearer <secret>` header carries the configured secret. Both sides are hashed first,
 * so the comparison takes the same time whatever the length or the first differing character.
 */
export function bearerMatches(header: string | null, secret: string): boolean {
  if (secret === "" || header === null || !header.startsWith("Bearer ")) return false;
  const digest = (value: string) => createHash("sha256").update(value).digest();
  return timingSafeEqual(digest(header.slice("Bearer ".length)), digest(secret));
}
