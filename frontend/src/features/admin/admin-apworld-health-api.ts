import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

/** Client of the apworld health admin API (story 38.3). */

/**
 * Every apworld incident query lives under this prefix: invalidating it after an action refreshes the
 * health page, the menu badge and the banner on a game's page at once.
 */
export const APWORLD_INCIDENTS_QUERY_KEY = ["admin-apworld-incidents"] as const;
export const APWORLD_INCIDENT_SUMMARY_QUERY_KEY = [...APWORLD_INCIDENTS_QUERY_KEY, "summary"] as const;

export type ApworldIncidentAdmin = { id: string; displayName: string };

export type ApworldIncident = {
  id: string;
  gameId: string;
  gameName: string;
  apworldHash: string;
  type: string;
  status: string;
  summary: string;
  error: string;
  openedAt: string;
  lastSeenAt: string;
  occurrences: number;
  acknowledgedBy: ApworldIncidentAdmin | null;
  acknowledgedAt: string | null;
  closedAt: string | null;
  closedBy: ApworldIncidentAdmin | null;
  closedAutomatically: boolean;
};

export type ApworldIncidentScope = "active" | "closed" | "all";

export type ApworldIncidentSummary = { active: number; unacknowledged: number };

// Story 38.9: how far the rolling test has come on the image in use.
export type ApworldSweepProgressData = { currentImage: string; testedOnCurrentImage: number; total: number };

export type ApworldIncidentActionResult = { ok: true } | { ok: false; message: string };

export const INCIDENT_TYPE_LABELS: Record<string, string> = {
  preflight_failed: "Test de génération en échec",
  // Story 38.6: a new version failed its test, the game kept its current one.
  update_rejected: "Mise à jour rejetée",
  // Story 38.6: several apworld files in one release, an admin has to pick.
  update_ambiguous: "Mise à jour à arbitrer",
  // Story 38.4: a real generation (a player's config test or a run) failed with the default YAML.
  default_yaml_failure: "Échec avec le YAML par défaut",
  // Story 38.9: passed on the previous image, fails twice in a row on the current one.
  image_regression: "Régression après changement d'image",
};

export const INCIDENT_STATUS_LABELS: Record<string, string> = {
  open: "Ouvert",
  acknowledged: "Pris en charge",
  resolved: "Résolu",
  ignored: "Ignoré",
};

const GENERIC_ACTION_ERROR = "L'action n'a pas pu être appliquée. Réessaie dans un instant.";

function isAdmin(v: unknown): v is ApworldIncidentAdmin {
  return typeof v === "object" && v !== null && hasStringProp(v, "id") && hasStringProp(v, "displayName");
}

function isNullableAdmin(v: unknown): v is ApworldIncidentAdmin | null {
  return v === null || isAdmin(v);
}

function isIncident(v: unknown): v is ApworldIncident {
  if (typeof v !== "object" || v === null) return false;
  for (const key of ["id", "gameId", "gameName", "apworldHash", "type", "status", "summary", "error", "openedAt", "lastSeenAt"]) {
    if (!hasStringProp(v, key)) return false;
  }
  if (!hasNumberProp(v, "occurrences") || !hasBooleanProp(v, "closedAutomatically")) return false;
  if (!hasNullableStringProp(v, "acknowledgedAt") || !hasNullableStringProp(v, "closedAt")) return false;
  return "acknowledgedBy" in v && isNullableAdmin(v.acknowledgedBy) && "closedBy" in v && isNullableAdmin(v.closedBy);
}

export async function fetchApworldIncidents(scope: ApworldIncidentScope, gameId?: string): Promise<ApworldIncident[] | null> {
  const params = new URLSearchParams({ status: scope });
  if (gameId !== undefined && gameId !== "") params.set("gameId", gameId);

  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/apworld-incidents?${params.toString()}`);
    if (!res.ok) return null;
    const json: unknown = await res.json();
    if (typeof json !== "object" || json === null || !("data" in json) || !Array.isArray(json.data)) return null;
    // All or nothing: a half-parsed list would hide incidents without saying so.
    return json.data.every(isIncident) ? json.data : null;
  } catch {
    return null;
  }
}

export async function fetchApworldIncidentSummary(): Promise<ApworldIncidentSummary | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/apworld-incidents/summary`);
    if (!res.ok) return null;
    const json: unknown = await res.json();
    if (typeof json !== "object" || json === null || !("data" in json)) return null;
    const data: unknown = json.data;
    if (typeof data !== "object" || data === null || !hasNumberProp(data, "active") || !hasNumberProp(data, "unacknowledged")) {
      return null;
    }
    return { active: data.active, unacknowledged: data.unacknowledged };
  } catch {
    return null;
  }
}

/** Story 38.9: null when the runner does not say which image runs, or on any failure. */
export async function fetchApworldSweepProgress(): Promise<ApworldSweepProgressData | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/apworld-incidents/sweep-progress`);
    if (!res.ok) return null;
    const json: unknown = await res.json();
    if (typeof json !== "object" || json === null || !("data" in json)) return null;
    const data: unknown = json.data;
    if (
      typeof data !== "object" ||
      data === null ||
      !hasStringProp(data, "currentImage") ||
      !hasNumberProp(data, "testedOnCurrentImage") ||
      !hasNumberProp(data, "total")
    ) {
      return null;
    }
    return { currentImage: data.currentImage, testedOnCurrentImage: data.testedOnCurrentImage, total: data.total };
  } catch {
    return null;
  }
}

async function act(id: string, action: "acknowledge" | "resolve" | "ignore"): Promise<ApworldIncidentActionResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/apworld-incidents/${encodeURIComponent(id)}/${action}`, { method: "POST" });
    if (res.ok) return { ok: true };
    const json: unknown = await res.json().catch(() => null);
    const error: unknown = typeof json === "object" && json !== null && "error" in json ? json.error : null;
    return {
      ok: false,
      message: typeof error === "object" && error !== null && hasStringProp(error, "message") ? error.message : GENERIC_ACTION_ERROR,
    };
  } catch {
    return { ok: false, message: GENERIC_ACTION_ERROR };
  }
}

export function acknowledgeApworldIncident(id: string): Promise<ApworldIncidentActionResult> {
  return act(id, "acknowledge");
}

export function resolveApworldIncident(id: string): Promise<ApworldIncidentActionResult> {
  return act(id, "resolve");
}

export function ignoreApworldIncident(id: string): Promise<ApworldIncidentActionResult> {
  return act(id, "ignore");
}
