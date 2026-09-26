import type { InstallStep } from "@/features/games/install-steps-editor";
import type { OptionTypesMap } from "@/lib/archipelago-yaml";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasNullableStringProp, hasStringProp } from "@/lib/type-guards";
import type { ApworldUpdateStatus } from "./apworld-update-status";

export type GameAvailability = "available" | "unavailable" | "experimental";

export type AdminGame = {
  id: string;
  name: string;
  slug: string;
  description: string;
  archipelagoDescription: string | null;
  coverImageUrl: string | null;
  coverImageAlt: string;
  coverImageCredit: string;
  availability: GameAvailability;
  disabled: boolean;
  disabledMessage: string | null;
  archipelagoGameName: string | null;
  isYamlReady: boolean;
  isApworldReady: boolean;
  apworldHash: string | null;
  apworldUploadedAt: string | null;
  defaultYaml: string | null;
  catalogSheetName: string | null;
  apworldSourceUrl: string | null;
  apworldDeployedVersion: string | null;
  apworldLatestVersion: string | null;
  apworldCheckedAt: string | null;
  apworldReleaseUrl: string | null;
  availabilityLocked: boolean;
  igdbId: number | null;
  platforms: string[];
  installSteps: InstallStep[];
  updateStatus: ApworldUpdateStatus;
  // Admin-only free-text notes (story 3.12). Present only in the admin detail payload, never public.
  adminNotes: string | null;
  // Story 9.38: upload-time solo test-generation verdict of the apworld. Null when never
  // checked or when the runner is unreachable. Absent on older payloads.
  apworldPreflight?: ApworldPreflight | null;
  // Story 38.8: the Archipelago image in use, and whether the verdict was produced on it. Null when
  // either is unknown.
  archipelagoRuntime?: { apImage: string; apImageId: string | null } | null;
  apworldPreflightOnCurrentImage?: boolean | null;
  apworldCandidate?: ApworldCandidate | null;
  // Story 9.47: true when `platforms` comes from an admin choice instead of IGDB.
  platformsOverridden?: boolean;
  // Curated families an admin can pick from. Absent on older payloads.
  selectablePlatforms?: string[];
  // Story 9.51/9.52: the option table as the editor sees it - introspection with the admin
  // curation already laid over it.
  optionTypes?: OptionTypesMap | null;
  // Story 9.52: the curation alone. What the admin decided, as opposed to what the apworld
  // declared, so the screen can show which is which and offer to hand an option back.
  dictOptionValues?: Record<string, Record<string, { values: string[]; closed: boolean }>> | null;
};

/**
 * Story 9.52: declare what the sub-settings of one dict option accept.
 *
 * Most `OptionDict` classes declare no vocabulary at all, so introspection has nothing to report
 * and the player gets free text fields. This is where a human says what the apworld does not.
 * An empty map hands the option back to introspection.
 */
export async function saveDictOptionValues(
  gameId: string,
  option: string,
  values: Record<string, { values: string[]; closed: boolean }>,
): Promise<DefaultYamlResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/games/${gameId}/dict-option-values`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ option, values }),
    });
    const payload: unknown = await res.json();
    if (res.status === 422) {
      return { kind: "invalid", message: readPlatformDetail(payload) };
    }
    if (!res.ok || !isAdminGamePayload(payload)) {
      return { kind: "error", message: "L'enregistrement a échoué." };
    }
    return { kind: "saved", game: payload.data, warning: null };
  } catch {
    return { kind: "error", message: "Impossible de contacter le serveur." };
  }
}

/**
 * Story 9.47: set the platforms shown for a game, or pass null to go back to the
 * IGDB-derived list.
 */
export async function savePlatforms(gameId: string, platforms: string[] | null): Promise<DefaultYamlResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/games/${gameId}/platforms`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ platforms }),
    });
    const payload: unknown = await res.json();
    if (res.status === 422) {
      return { kind: "invalid", message: readPlatformDetail(payload) };
    }
    if (!res.ok || !isAdminGamePayload(payload)) {
      return { kind: "error", message: "L'enregistrement a échoué." };
    }
    return { kind: "saved", game: payload.data, warning: null };
  } catch {
    return { kind: "error", message: "Impossible de contacter le serveur." };
  }
}

function readPlatformDetail(payload: unknown): string {
  if (typeof payload === "object" && payload !== null && "error" in payload) {
    const err: unknown = payload.error;
    if (typeof err === "object" && err !== null && "details" in err) {
      const details: unknown = err.details;
      if (typeof details === "object" && details !== null && "platforms" in details) {
        const list: unknown = details.platforms;
        if (Array.isArray(list) && typeof list[0] === "string") return list[0];
      }
    }
  }
  return "Plateformes invalides.";
}

// Story 9.38: verdict of the solo test generation run at apworld upload.
export type ApworldPreflight = {
  status: "pending" | "passed" | "failed" | "skipped";
  error: string;
  checkedAt: string;
  overridden: boolean;
  // True only for failed + non-overridden: the game cannot be newly added to a run.
  blocks: boolean;
  // Story 38.8: the Archipelago image the verdict was produced on; absent before that story.
  image?: string | null;
  imageId?: string | null;
};

export function isApworldPreflight(v: unknown): v is ApworldPreflight {
  if (typeof v !== "object" || v === null) return false;
  if (!("status" in v) || typeof v.status !== "string") return false;
  if (!["pending", "passed", "failed", "skipped"].includes(v.status)) return false;
  return "blocks" in v && typeof v.blocks === "boolean";
}

// Story 9.45/9.46: the default template is what players receive, so saving it can also
// report a soft warning (saved, but the verdict could not be refreshed).
export type DefaultYamlResult =
  | { kind: "saved"; game: AdminGame; warning: string | null }
  | { kind: "invalid"; message: string }
  | { kind: "error"; message: string };

function readDetail(payload: unknown, fallback: string): string {
  if (typeof payload === "object" && payload !== null && "error" in payload) {
    const err: unknown = payload.error;
    if (typeof err === "object" && err !== null && "details" in err) {
      const details: unknown = err.details;
      if (typeof details === "object" && details !== null && "defaultYaml" in details) {
        const list: unknown = details.defaultYaml;
        if (Array.isArray(list) && typeof list[0] === "string") return list[0];
      }
      if (typeof details === "object" && details !== null && "apworld" in details) {
        const list: unknown = details.apworld;
        if (Array.isArray(list) && typeof list[0] === "string") return list[0];
      }
    }
    if (typeof err === "object" && err !== null && "message" in err && typeof err.message === "string") {
      return err.message;
    }
  }
  return fallback;
}

function readWarning(payload: unknown): string | null {
  if (typeof payload === "object" && payload !== null && "meta" in payload) {
    const meta: unknown = payload.meta;
    if (typeof meta === "object" && meta !== null && "warning" in meta && typeof meta.warning === "string") {
      return meta.warning;
    }
  }
  return null;
}

/** Save the default YAML template served to players. */
export async function saveDefaultYaml(gameId: string, defaultYaml: string): Promise<DefaultYamlResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/games/${gameId}/default-yaml`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ defaultYaml }),
    });
    const payload: unknown = await res.json();
    if (res.status === 422) {
      return { kind: "invalid", message: readDetail(payload, "Template invalide.") };
    }
    if (!res.ok || !isAdminGamePayload(payload)) {
      return { kind: "error", message: "L'enregistrement a échoué." };
    }
    return { kind: "saved", game: payload.data, warning: readWarning(payload) };
  } catch {
    return { kind: "error", message: "Impossible de contacter le serveur." };
  }
}

/** Regenerate the template from the stored apworld, discarding edits. */
export async function regenerateDefaultYaml(gameId: string): Promise<DefaultYamlResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/games/${gameId}/default-yaml/regenerate`, {
      method: "POST",
    });
    const payload: unknown = await res.json();
    if (res.status === 422) {
      return { kind: "invalid", message: readDetail(payload, "La régénération a échoué.") };
    }
    if (!res.ok || !isAdminGamePayload(payload)) {
      return { kind: "error", message: "La régénération a échoué." };
    }
    return { kind: "saved", game: payload.data, warning: null };
  } catch {
    return { kind: "error", message: "Impossible de contacter le serveur." };
  }
}

/** Queue a preflight re-run (async on the orchestrator). Returns whether it was accepted. */
export async function rerunApworldPreflight(gameId: string): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/games/${gameId}/apworld-preflight`, { method: "POST" });
    return res.ok;
  } catch {
    return false;
  }
}

/** Toggle the "force allow" override on a failed verdict. Returns the updated verdict or null. */
export async function overrideApworldPreflight(gameId: string, overridden: boolean): Promise<ApworldPreflight | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/games/${gameId}/apworld-preflight-override`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ overridden }),
    });
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload)) return null;
    const data: unknown = payload.data;
    if (typeof data !== "object" || data === null || !("preflight" in data)) return null;
    return isApworldPreflight(data.preflight) ? data.preflight : null;
  } catch {
    return null;
  }
}

// Story 38.6: a new apworld version is tested before it serves players. The candidate is that version
// while it waits for its verdict, or once its test refused it.
export type ApworldCandidate = {
  id: string;
  // Story 38.6 review: "expired" = no verdict in time; the release is tried again, not rejected.
  status: "testing" | "rejected" | "expired";
  apworldHash: string;
  versionTag: string | null;
  origin: "manual" | "auto";
  submittedAt: string;
  decidedAt: string | null;
  rejectionReason: string | null;
};

export function isApworldCandidate(v: unknown): v is ApworldCandidate {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "id") || !hasStringProp(v, "apworldHash") || !hasStringProp(v, "submittedAt")) return false;
  if (!("status" in v) || (v.status !== "testing" && v.status !== "rejected" && v.status !== "expired")) return false;
  if (!("origin" in v) || (v.origin !== "manual" && v.origin !== "auto")) return false;
  return hasNullableStringProp(v, "versionTag") && hasNullableStringProp(v, "decidedAt") && hasNullableStringProp(v, "rejectionReason");
}

export type ApworldCandidateActionResult = { ok: true } | { ok: false; message: string };

const CANDIDATE_ACTION_ERROR = "L'action n'a pas pu être appliquée. Réessaie dans un instant.";

async function candidateAction(gameId: string, action: "promote" | "retry"): Promise<ApworldCandidateActionResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/games/${encodeURIComponent(gameId)}/apworld-candidate/${action}`, { method: "POST" });
    if (res.ok) return { ok: true };
    const json: unknown = await res.json().catch(() => null);
    const error: unknown = typeof json === "object" && json !== null && "error" in json ? json.error : null;
    return { ok: false, message: typeof error === "object" && error !== null && hasStringProp(error, "message") ? error.message : CANDIDATE_ACTION_ERROR };
  } catch {
    return { ok: false, message: CANDIDATE_ACTION_ERROR };
  }
}

/** Put the candidate into service despite its test (after confirmation in the UI). */
export function forceApworldCandidate(gameId: string): Promise<ApworldCandidateActionResult> {
  return candidateAction(gameId, "promote");
}

/** Run the test of a rejected candidate again, typically after a transient failure. */
export function retryApworldCandidate(gameId: string): Promise<ApworldCandidateActionResult> {
  return candidateAction(gameId, "retry");
}

// Discriminated result: keeps the editor's four failure screens distinct. Never throws
// (AC-API2) - the old effect was one-shot too.
export type AdminGameResult =
  | { kind: "ready"; game: AdminGame }
  | { kind: "denied" }
  | { kind: "not_found" }
  | { kind: "error" };

export async function fetchAdminGame(gameId: string): Promise<AdminGameResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/games/${gameId}`);

    if (res.status === 401 || res.status === 403) {
      return { kind: "denied" };
    }
    if (res.status === 404) {
      return { kind: "not_found" };
    }
    if (!res.ok) {
      return { kind: "error" };
    }

    const payload: unknown = await res.json();
    return isAdminGamePayload(payload) ? { kind: "ready", game: payload.data } : { kind: "error" };
  } catch {
    return { kind: "error" };
  }
}

// Exported for the editor's mutation handlers, which parse the PATCH/POST responses with the
// same guard before pushing the updated game back into the query cache.
export function isAdminGamePayload(payload: unknown): payload is { data: AdminGame } {
  if (typeof payload !== "object" || payload === null || !("data" in payload)) return false;
  const data: unknown = payload.data;
  if (typeof data !== "object" || data === null) return false;
  return "id" in data && "name" in data;
}
