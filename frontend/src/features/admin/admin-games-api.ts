import type { InstallStep } from "@/features/games/install-steps-editor";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

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
  updateStatus: "update_available" | "up_to_date" | "unknown" | "not_tracked";
  // Admin-only free-text notes (story 3.12). Present only in the admin detail payload, never public.
  adminNotes: string | null;
  // Story 9.38: upload-time solo test-generation verdict of the apworld. Null when never
  // checked or when the runner is unreachable. Absent on older payloads.
  apworldPreflight?: ApworldPreflight | null;
};

// Story 9.38: verdict of the solo test generation run at apworld upload.
export type ApworldPreflight = {
  status: "pending" | "passed" | "failed" | "skipped";
  error: string;
  checkedAt: string;
  overridden: boolean;
  // True only for failed + non-overridden: the game cannot be newly added to a run.
  blocks: boolean;
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
