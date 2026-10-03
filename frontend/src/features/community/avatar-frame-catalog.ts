import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasStringProp } from "@/lib/type-guards";
import type { AvatarFrameConfig, AvatarFrameVideo } from "./avatar-frames";

/** Story 41.10: who may wear a video frame of the catalog. */
export type AvatarFrameAccess = "free" | "members" | "admins" | "shop";

/**
 * A video frame of the admin catalog. A built-in one (the Légendaires of story 30.46) carries no files: the frontend
 * has them in its own catalog; the API only tells its name and access.
 */
export type AvatarFrameCatalogEntry = { key: string; label: string; access: AvatarFrameAccess; builtIn: boolean; video: AvatarFrameVideo | null };

export const AVATAR_FRAME_CATALOG_QUERY_KEY = ["avatar-frame-catalog"] as const;

const ACCESSES: readonly AvatarFrameAccess[] = ["free", "members", "admins", "shop"];

function isVideo(v: unknown): v is AvatarFrameVideo {
  return typeof v === "object" && v !== null && hasStringProp(v, "webm") && hasStringProp(v, "mp4") && hasStringProp(v, "poster") && hasStringProp(v, "still");
}

function isEntry(v: unknown): v is AvatarFrameCatalogEntry {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "key") &&
    hasStringProp(v, "label") &&
    hasStringProp(v, "access") &&
    ACCESSES.some((a) => a === v.access) &&
    hasBooleanProp(v, "builtIn") &&
    "video" in v &&
    (v.video === null || isVideo(v.video))
  );
}

export function isAvatarFrameCatalog(v: unknown): v is { frames: AvatarFrameCatalogEntry[] } {
  return typeof v === "object" && v !== null && "frames" in v && Array.isArray(v.frames) && v.frames.every(isEntry);
}

/** An empty catalog when the API is out of reach: the code's frames still show. */
export async function fetchAvatarFrameCatalog(): Promise<AvatarFrameCatalogEntry[]> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/avatar-frames`);
    if (!res.ok) return [];
    const payload: unknown = await res.json();
    return isAvatarFrameCatalog(payload) ? payload.frames : [];
  } catch {
    return [];
  }
}

/** A catalog frame drawn like a code video frame, under the Légendaires heading. */
export function catalogFrameConfig(entry: AvatarFrameCatalogEntry): AvatarFrameConfig | null {
  return entry.video === null ? null : { key: entry.key, label: entry.label, category: "Légendaires", variant: "video", video: entry.video };
}

/**
 * Why a frame is locked for this member, or null when they may pick it (stories 41.7 and 41.10). A frame the catalog
 * does not name keeps the code's rule: a legendary one is for admins, a shop one for who bought it.
 */
export function frameLockReason(
  frame: { key: string; legendary: boolean; shop: boolean },
  access: AvatarFrameAccess | undefined,
  rights: { admin: boolean; member: boolean; owned: readonly string[] },
): string | null {
  const effective: AvatarFrameAccess = access ?? (frame.shop ? "shop" : frame.legendary ? "admins" : "free");
  switch (effective) {
    case "free":
      return null;
    case "members":
      return rights.admin || rights.member ? null : "Réservé aux adhérents";
    case "admins":
      return rights.admin ? null : "Réservé aux admins pour l'instant";
    case "shop":
      return rights.owned.includes(frame.key) ? null : "En boutique";
  }
}
