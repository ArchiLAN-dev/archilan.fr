import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

export const SESSION_CONFIG_TYPES = ["private", "event", "weekly"] as const;
export type SessionConfigType = (typeof SESSION_CONFIG_TYPES)[number];

export const RELEASE_COLLECT_MODES = ["disabled", "enabled", "goal", "auto", "auto-enabled"] as const;
export const REMAINING_MODES = ["enabled", "disabled", "goal"] as const;
export const COUNTDOWN_MODES = ["enabled", "disabled", "auto"] as const;
export const COMPATIBILITY_VALUES = [2, 1, 0] as const;
export const SPOILER_LEVELS = [0, 1, 2, 3] as const;
export const PLANDO_OPTIONS = ["bosses", "items", "texts", "connections"] as const;

export type SessionServerConfig = {
  releaseMode: string;
  collectMode: string;
  remainingMode: string;
  disableItemCheat: boolean;
  hintCost: number;
  locationCheckPoints: number;
  countdownMode: string;
  autoShutdown: number;
  compatibility: number;
  joinPassword: string | null;
};

export type SessionGenerationConfig = {
  plandoOptions: string[];
  race: boolean;
  spoiler: number;
};

export type SessionConfig = {
  server: SessionServerConfig;
  generation: SessionGenerationConfig;
};

export type UpdateSessionConfigResult =
  | { ok: true; config: SessionConfig }
  | { ok: false; error: string };

// ── Fetch / mutate ───────────────────────────────────────────────────────────

export async function fetchSessionConfig(type: SessionConfigType): Promise<SessionConfig | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/session-config/${type}`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return extractConfig(payload);
  } catch {
    return null;
  }
}

export async function updateSessionConfig(
  type: SessionConfigType,
  config: SessionConfig,
): Promise<UpdateSessionConfigResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/session-config/${type}`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(config),
    });
    if (res.status === 200) {
      const payload: unknown = await res.json();
      const saved = extractConfig(payload);
      return saved !== null
        ? { ok: true, config: saved }
        : { ok: false, error: "invalid_response" };
    }
    const err: unknown = await res.json().catch(() => null);
    return { ok: false, error: extractErrorCode(err) };
  } catch {
    return { ok: false, error: "network_error" };
  }
}

// ── Per-scope override (admin scope endpoints + owner run endpoint share the shape) ─────────

export type OverrideResult = { ok: true } | { ok: false; error: string };

// Loads the partial override stored for an endpoint ({ data: { override: {...} } }), or {}.
export async function loadOverride(path: string): Promise<Record<string, unknown>> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}${path}`);
    if (!res.ok) return {};
    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload)) return {};
    const data: unknown = payload.data;
    if (typeof data !== "object" || data === null || !("override" in data)) return {};
    const override: unknown = data.override;
    return typeof override === "object" && override !== null ? { ...override } : {};
  } catch {
    return {};
  }
}

export async function saveOverride(path: string, override: Record<string, unknown>): Promise<OverrideResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}${path}`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(override),
    });
    if (res.status === 200) return { ok: true };
    const err: unknown = await res.json().catch(() => null);
    return { ok: false, error: extractErrorCode(err) };
  } catch {
    return { ok: false, error: "network_error" };
  }
}

// Reads the resolved profile config returned alongside an override ({ data: { profile: {...} } }).
// Used by override editors to display inherited values; returns null when absent.
export async function loadOverrideProfile(path: string): Promise<SessionConfig | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}${path}`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload)) return null;
    const data: unknown = payload.data;
    if (typeof data !== "object" || data === null || !("profile" in data)) return null;
    return isSessionConfig(data.profile) ? data.profile : null;
  } catch {
    return null;
  }
}

export async function clearOverride(path: string): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}${path}`, { method: "DELETE" });
    return res.status === 204;
  } catch {
    return false;
  }
}

// ── Type guards ──────────────────────────────────────────────────────────────

function extractConfig(payload: unknown): SessionConfig | null {
  if (typeof payload !== "object" || payload === null || !("data" in payload)) return null;
  const data: unknown = payload.data;
  if (typeof data !== "object" || data === null || !("config" in data)) return null;
  const config: unknown = data.config;
  return isSessionConfig(config) ? config : null;
}

function isSessionConfig(v: unknown): v is SessionConfig {
  if (typeof v !== "object" || v === null) return false;
  if (!("server" in v) || !("generation" in v)) return false;
  return isServerConfig(v.server) && isGenerationConfig(v.generation);
}

function isServerConfig(v: unknown): v is SessionServerConfig {
  if (typeof v !== "object" || v === null) return false;
  if (!("releaseMode" in v) || typeof v.releaseMode !== "string") return false;
  if (!("collectMode" in v) || typeof v.collectMode !== "string") return false;
  if (!("remainingMode" in v) || typeof v.remainingMode !== "string") return false;
  if (!("countdownMode" in v) || typeof v.countdownMode !== "string") return false;
  if (!("disableItemCheat" in v) || typeof v.disableItemCheat !== "boolean") return false;
  if (!("hintCost" in v) || typeof v.hintCost !== "number") return false;
  if (!("locationCheckPoints" in v) || typeof v.locationCheckPoints !== "number") return false;
  if (!("autoShutdown" in v) || typeof v.autoShutdown !== "number") return false;
  if (!("compatibility" in v) || typeof v.compatibility !== "number") return false;
  if (!("joinPassword" in v)) return false;
  return v.joinPassword === null || typeof v.joinPassword === "string";
}

function isGenerationConfig(v: unknown): v is SessionGenerationConfig {
  if (typeof v !== "object" || v === null) return false;
  if (!("plandoOptions" in v) || !Array.isArray(v.plandoOptions)) return false;
  if (!v.plandoOptions.every((p): p is string => typeof p === "string")) return false;
  if (!("race" in v) || typeof v.race !== "boolean") return false;
  return "spoiler" in v && typeof v.spoiler === "number";
}

function extractErrorCode(v: unknown): string {
  if (typeof v === "object" && v !== null && "error" in v) {
    const err: unknown = v.error;
    if (typeof err === "string") return err;
    if (typeof err === "object" && err !== null && "code" in err && typeof err.code === "string") {
      return err.code;
    }
  }
  return "update_failed";
}
