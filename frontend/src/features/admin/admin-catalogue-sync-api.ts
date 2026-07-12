import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

// ── Types ────────────────────────────────────────────────────────────────────

export type CatalogLink = { label: string; url: string | null };

export type NewGame = {
  name: string;
  availability: string;
  bundledWithAp: boolean;
  adultContent: boolean;
  links: CatalogLink[];
  votes: number;
};

export type StabilityChange = {
  gameId: string;
  gameName: string;
  currentAvailability: string;
  newAvailability: string;
  availabilityLocked: boolean;
};

export type RemovedGame = {
  gameId: string;
  gameName: string;
};

export type ApworldUpdate = {
  gameId: string;
  gameName: string;
  deployedVersion: string | null;
  latestVersion: string | null;
  releaseUrl: string | null;
  publishedAt: string | null;
  updateStatus: "update_available" | "up_to_date" | "unknown" | "not_tracked";
};

export type IgnoredGame = {
  name: string;
  ignoredAt: string;
};

export type CatalogSyncData = {
  cachedAt: string | null;
  googleApiAvailable: boolean;
  githubChecksAvailable: boolean;
  newGames: NewGame[];
  ignoredGames: IgnoredGame[];
  stabilityChanged: StabilityChange[];
  removedFromSheet: RemovedGame[];
  apworldUpdates: ApworldUpdate[];
};

export type AdminGameBase = {
  name: string;
  slug: string;
  description: string;
  coverImageUrl: string | null;
  coverImageAlt: string;
  coverImageCredit: string;
};

// Discriminated result (instead of the usual `T | null`) so the page keeps rendering the 503 case
// (Google Sheets down - the api forwards its own error message) differently from other failures.
// Never throws - every outcome is encoded in the result's `kind`.
export type CatalogSyncResult =
  | { kind: "ready"; data: CatalogSyncData }
  | { kind: "error"; message: string };

// ── Guards ───────────────────────────────────────────────────────────────────

// Item shapes inside the arrays are trusted (single publisher: the admin catalog-sync endpoint);
// the envelope fields are checked structurally.
function isCatalogSyncData(v: unknown): v is CatalogSyncData {
  if (typeof v !== "object" || v === null) return false;
  return (
    hasNullableStringProp(v, "cachedAt") &&
    hasBooleanProp(v, "googleApiAvailable") &&
    hasBooleanProp(v, "githubChecksAvailable") &&
    "newGames" in v && Array.isArray(v.newGames) &&
    "ignoredGames" in v && Array.isArray(v.ignoredGames) &&
    "stabilityChanged" in v && Array.isArray(v.stabilityChanged) &&
    "removedFromSheet" in v && Array.isArray(v.removedFromSheet) &&
    "apworldUpdates" in v && Array.isArray(v.apworldUpdates)
  );
}

function isAdminGameBase(v: unknown): v is AdminGameBase {
  if (typeof v !== "object" || v === null) return false;
  return (
    hasStringProp(v, "name") &&
    hasStringProp(v, "slug") &&
    hasStringProp(v, "description") &&
    hasNullableStringProp(v, "coverImageUrl") &&
    hasStringProp(v, "coverImageAlt") &&
    hasStringProp(v, "coverImageCredit")
  );
}

function extractErrorMessage(body: unknown): string | null {
  if (typeof body !== "object" || body === null) return null;
  if (!("error" in body) || typeof body.error !== "object" || body.error === null) return null;
  return hasStringProp(body.error, "message") ? body.error.message : null;
}

// ── Fetches ──────────────────────────────────────────────────────────────────

export async function fetchCatalogSync(force = false): Promise<CatalogSyncResult> {
  const url = `${env.apiBaseUrl}/admin/catalog-sync${force ? "?force=true" : ""}`;
  try {
    const res = await apiFetch(url);
    if (!res.ok) {
      if (res.status === 503) {
        const body: unknown = await res.json().catch(() => null);
        return {
          kind: "error",
          message: extractErrorMessage(body) ?? "Le catalogue Google Sheets est injoignable.",
        };
      }
      return { kind: "error", message: "Impossible de charger la synchronisation catalogue." };
    }
    const payload: unknown = await res.json();
    if (!isCatalogSyncData(payload)) {
      return { kind: "error", message: "Impossible de charger la synchronisation catalogue." };
    }
    return { kind: "ready", data: payload };
  } catch {
    return { kind: "error", message: "Impossible de contacter l'API." };
  }
}

/** Vote counts per normalized game name, used to decorate the "new games" diff. */
export async function fetchGameRequestVotes(): Promise<Map<string, number>> {
  const map = new Map<string, number>();
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/game-requests`);
    if (!res.ok) return map;
    const json: unknown = await res.json();
    if (typeof json !== "object" || json === null || !("data" in json) || !Array.isArray(json.data)) {
      return map;
    }
    for (const item of json.data) {
      if (
        typeof item === "object" &&
        item !== null &&
        hasStringProp(item, "normalizedName") &&
        hasNumberProp(item, "voteCount")
      ) {
        map.set(item.normalizedName, item.voteCount);
      }
    }
    return map;
  } catch {
    return map;
  }
}

export async function fetchAdminGameBase(gameId: string): Promise<AdminGameBase | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/games/${gameId}`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload) || !isAdminGameBase(payload.data)) {
      return null;
    }
    return payload.data;
  } catch {
    return null;
  }
}

// ── Actions ──────────────────────────────────────────────────────────────────

export type CheckUpdatesSummary = { checked: number; rateLimitHit: boolean };

export async function checkApworldUpdates(): Promise<CheckUpdatesSummary | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/catalog-sync/check-updates`, { method: "POST" });
    if (!res.ok) return null;
    const body: unknown = await res.json().catch(() => null);
    const checked =
      typeof body === "object" && body !== null && hasNumberProp(body, "checked") ? body.checked : 0;
    const rateLimitHit =
      typeof body === "object" && body !== null && hasBooleanProp(body, "rateLimitHit")
        ? body.rateLimitHit
        : false;
    return { checked, rateLimitHit };
  } catch {
    return null;
  }
}

export async function updateGameAvailability(
  gameId: string,
  game: AdminGameBase,
  availability: string,
): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/games/${gameId}`, {
      method: "PATCH",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        name: game.name,
        slug: game.slug,
        description: game.description,
        coverImageUrl: game.coverImageUrl,
        coverImageAlt: game.coverImageAlt,
        coverImageCredit: game.coverImageCredit,
        availability,
      }),
    });
    return res.ok;
  } catch {
    return false;
  }
}

export async function ignoreCatalogGame(name: string): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/catalog-sync/ignored`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ name }),
    });
    return res.ok;
  } catch {
    return false;
  }
}

export async function unignoreCatalogGame(name: string): Promise<boolean> {
  try {
    const res = await apiFetch(
      `${env.apiBaseUrl}/admin/catalog-sync/ignored/${encodeURIComponent(name)}`,
      { method: "DELETE" },
    );
    return res.ok;
  } catch {
    return false;
  }
}
