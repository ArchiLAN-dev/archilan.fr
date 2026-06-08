import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

// Shared TanStack Query keys for the admin weekly-runs views. Exported so the
// create/edit form can invalidate them after a mutation (avoids magic-string drift).
export const ADMIN_WEEKLY_GAMES_QUERY_KEY = ["admin-weekly-games"] as const;
export const ADMIN_WEEKLY_GAME_DETAIL_QUERY_KEY = ["admin-weekly-game-detail"] as const;

export type AdminWeeklyTemplate = {
  id: string;
  name: string | null;
  gameId: string;
  gameName: string;
  yamlConfig: string;
  maxAttempts: number | null;
  isActive: boolean;
};

export type AdminWeeklyTemplateListItem = {
  id: string;
  name: string | null;
  gameId: string;
  gameName: string;
  maxAttempts: number | null;
  isActive: boolean;
  createdAt: string;
};

export type AdminWeeklyRunEntry = {
  userId: string;
  displayName: string;
  attemptNumber: number;
  externalSessionId: string | null;
  launchedAt: string | null;
  goalReachedAt: string | null;
  completionTimeSeconds: number | null;
  checksTotal: number | null;
  itemsTotal: number | null;
};

export type AdminWeeklyRunGame = {
  gameId: string;
  gameName: string;
  coverImageUrl: string | null;
  coverImageAlt: string;
  templateCount: number;
  runCount: number;
};

export type AdminCurrentWeeklyRun = {
  weeklyRunId: string;
  templateName: string | null;
  gameId: string;
  gameName: string;
  status: "active" | "finished";
  seed: string;
  startedAt: string | null;
  finishedAt: string | null;
  entryCount: number;
  entries: AdminWeeklyRunEntry[];
};

// A weekly run of a given template, including past weeks. Superset of the
// current-runs shape with the ISO week it belongs to.
export type AdminTemplateRun = AdminCurrentWeeklyRun & {
  weekYear: number;
  weekNumber: number;
  // True when the run's generated multidata is available for download from storage.
  hasOutput: boolean;
};

export type CreateTemplatePayload = {
  gameId: string;
  yamlConfig: string;
  name?: string | null;
  maxAttempts?: number | null;
};

export type UpdateTemplatePayload = {
  name?: string | null;
  yamlConfig?: string;
  maxAttempts?: number | null;
  isActive?: boolean;
};

export type AdminGameOption = {
  id: string;
  name: string;
  isApworldReady: boolean;
  coverImageUrl?: string | null;
  defaultYaml?: string | null;
};

// ── Fetch functions ────────────────────────────────────────────────────────────

// Searches the game catalogue server-side, restricted to APWorld-ready games
// (apworld_ready=1). The endpoint is paginated; the picker only needs the first
// page of matches, so we cap it at a small per_page. Empty query → no request.
const GAME_SEARCH_LIMIT = 20;

export async function searchAdminGameOptions(
  query: string,
  signal?: AbortSignal,
): Promise<AdminGameOption[]> {
  const q = query.trim();
  if (q === "") return [];
  try {
    const res = await apiFetch(
      `${env.apiBaseUrl}/admin/games?search=${encodeURIComponent(q)}&apworld_ready=1&per_page=${GAME_SEARCH_LIMIT}`,
      { signal },
    );
    if (!res.ok) return [];
    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload) || !Array.isArray(payload.data)) {
      return [];
    }
    const rawItems: unknown[] = payload.data;
    return rawItems
      .filter((g): g is { id: string; name: string; isApworldReady: boolean; coverImageUrl?: unknown } => {
        if (typeof g !== "object" || g === null) return false;
        if (!("id" in g) || typeof g.id !== "string") return false;
        if (!("name" in g) || typeof g.name !== "string") return false;
        if (!("isApworldReady" in g) || typeof g.isApworldReady !== "boolean") return false;
        return true;
      })
      .filter((g) => g.isApworldReady)
      .map((g) => ({
        id: g.id,
        name: g.name,
        isApworldReady: g.isApworldReady,
        coverImageUrl: typeof g.coverImageUrl === "string" && g.coverImageUrl !== "" ? g.coverImageUrl : null,
      }));
  } catch {
    return [];
  }
}

export async function fetchAdminGameOptionDetail(id: string): Promise<AdminGameOption | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/games/${id}`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload)) return null;
    const data: unknown = payload.data;
    if (typeof data !== "object" || data === null) return null;
    if (!("id" in data) || typeof data.id !== "string") return null;
    if (!("name" in data) || typeof data.name !== "string") return null;
    if (!("isApworldReady" in data) || typeof data.isApworldReady !== "boolean") return null;
    return {
      id: data.id,
      name: data.name,
      isApworldReady: data.isApworldReady,
      defaultYaml: "defaultYaml" in data && typeof data.defaultYaml === "string" ? data.defaultYaml : null,
    };
  } catch {
    return null;
  }
}

export async function fetchAdminWeeklyTemplates(): Promise<{
  data: AdminWeeklyTemplateListItem[];
  meta: { total: number };
} | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/weekly-templates`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return isTemplateListPayload(payload) ? payload : null;
  } catch {
    return null;
  }
}

export async function fetchAdminWeeklyTemplate(id: string): Promise<AdminWeeklyTemplate | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/weekly-templates/${id}`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return isTemplateDetailPayload(payload) ? payload.data : null;
  } catch {
    return null;
  }
}

export type CreateTemplateResult =
  | { ok: true; template: AdminWeeklyTemplate }
  | { ok: false; error: string };

export async function createAdminWeeklyTemplate(
  payload: CreateTemplatePayload,
): Promise<CreateTemplateResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/weekly-templates`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    if (!res.ok) {
      const err: unknown = await res.json().catch(() => null);
      return { ok: false, error: isErrorPayload(err) ? err.error : "create_failed" };
    }
    const data: unknown = await res.json();
    if (!isTemplateDetailPayload(data)) return { ok: false, error: "invalid_response" };
    return { ok: true, template: data.data };
  } catch {
    return { ok: false, error: "network_error" };
  }
}

export async function updateAdminWeeklyTemplate(
  id: string,
  payload: UpdateTemplatePayload,
): Promise<AdminWeeklyTemplate | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/weekly-templates/${id}`, {
      method: "PATCH",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    if (!res.ok) return null;
    const data: unknown = await res.json();
    return isTemplateDetailPayload(data) ? data.data : null;
  } catch {
    return null;
  }
}

export async function deactivateAdminWeeklyTemplate(id: string): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/weekly-templates/${id}`, {
      method: "DELETE",
    });
    return res.status === 204;
  } catch {
    return false;
  }
}

export async function triggerAdminWeeklyRunsGeneration(): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/weekly-runs/generate`, { method: "POST" });
    return res.status === 204;
  } catch {
    return false;
  }
}

export async function fetchAdminCurrentWeeklyRuns(): Promise<AdminCurrentWeeklyRun[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/weekly-runs/current`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return isCurrentRunsPayload(payload) ? payload.data : null;
  } catch {
    return null;
  }
}

// All runs (current week + past) of a single template, most recent week first.
export async function fetchAdminTemplateRuns(templateId: string): Promise<AdminTemplateRun[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/weekly-templates/${templateId}/runs`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload) || !Array.isArray(payload.data)) {
      return null;
    }
    const rawItems: unknown[] = payload.data;
    return rawItems.filter(isAdminTemplateRun);
  } catch {
    return null;
  }
}

// Downloads a weekly run's generated multidata (admin-only) as a file.
export async function downloadAdminWeeklyRunOutput(weeklyRunId: string): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/weekly-runs/${weeklyRunId}/output`);
    if (!res.ok) return false;
    const blob = await res.blob();
    const disposition = res.headers.get("Content-Disposition") ?? "";
    const match = /filename="?([^"]+)"?/.exec(disposition);
    // The artifact is a zip archive; fall back to a .zip name when the
    // Content-Disposition header is unreadable (e.g. not CORS-exposed).
    const filename = match?.[1] ?? `weekly-run-${weeklyRunId}.zip`;
    const objectUrl = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = objectUrl;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(objectUrl);
    return true;
  } catch {
    return false;
  }
}

// Games that have at least one weekly template, each with its total run count.
// Powers the admin weekly-runs landing grid (one card per targeted game).
export async function fetchAdminWeeklyRunGames(): Promise<AdminWeeklyRunGame[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/weekly-runs/games`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload) || !Array.isArray(payload.data)) {
      return null;
    }
    const rawItems: unknown[] = payload.data;
    return rawItems.filter(isAdminWeeklyRunGame);
  } catch {
    return null;
  }
}

// ── Type guards ────────────────────────────────────────────────────────────────

function isTemplateListPayload(
  v: unknown,
): v is { data: AdminWeeklyTemplateListItem[]; meta: { total: number } } {
  if (typeof v !== "object" || v === null) return false;
  if (!("data" in v) || !Array.isArray(v.data)) return false;
  if (!("meta" in v) || typeof v.meta !== "object" || v.meta === null) return false;
  return "total" in v.meta && typeof v.meta.total === "number";
}

function isTemplateDetailPayload(v: unknown): v is { data: AdminWeeklyTemplate } {
  if (typeof v !== "object" || v === null) return false;
  if (!("data" in v) || typeof v.data !== "object" || v.data === null) return false;
  const d: unknown = v.data;
  if (typeof d !== "object" || d === null) return false;
  if (!("id" in d) || typeof d.id !== "string") return false;
  if (!("gameId" in d) || typeof d.gameId !== "string") return false;
  if (!("yamlConfig" in d) || typeof d.yamlConfig !== "string") return false;
  if (!("isActive" in d) || typeof d.isActive !== "boolean") return false;
  return true;
}

function isAdminWeeklyRunEntry(v: unknown): v is AdminWeeklyRunEntry {
  if (typeof v !== "object" || v === null) return false;
  if (!("userId" in v) || typeof v.userId !== "string") return false;
  if (!("displayName" in v) || typeof v.displayName !== "string") return false;
  return "attemptNumber" in v && typeof v.attemptNumber === "number";
}

function isAdminTemplateRun(v: unknown): v is AdminTemplateRun {
  if (typeof v !== "object" || v === null) return false;
  if (!("weeklyRunId" in v) || typeof v.weeklyRunId !== "string") return false;
  if (!("gameId" in v) || typeof v.gameId !== "string") return false;
  if (!("gameName" in v) || typeof v.gameName !== "string") return false;
  if (!("status" in v) || (v.status !== "active" && v.status !== "finished")) return false;
  if (!("seed" in v) || typeof v.seed !== "string") return false;
  if (!("weekYear" in v) || typeof v.weekYear !== "number") return false;
  if (!("weekNumber" in v) || typeof v.weekNumber !== "number") return false;
  if (!("hasOutput" in v) || typeof v.hasOutput !== "boolean") return false;
  if (!("entryCount" in v) || typeof v.entryCount !== "number") return false;
  if (!("entries" in v) || !Array.isArray(v.entries)) return false;
  return v.entries.every(isAdminWeeklyRunEntry);
}

function isAdminWeeklyRunGame(v: unknown): v is AdminWeeklyRunGame {
  if (typeof v !== "object" || v === null) return false;
  if (!("gameId" in v) || typeof v.gameId !== "string") return false;
  if (!("gameName" in v) || typeof v.gameName !== "string") return false;
  if (!("coverImageAlt" in v) || typeof v.coverImageAlt !== "string") return false;
  if (!("templateCount" in v) || typeof v.templateCount !== "number") return false;
  if (!("runCount" in v) || typeof v.runCount !== "number") return false;
  const cover = "coverImageUrl" in v ? v.coverImageUrl : null;
  return cover === null || typeof cover === "string";
}

function isCurrentRunsPayload(v: unknown): v is { data: AdminCurrentWeeklyRun[] } {
  if (typeof v !== "object" || v === null) return false;
  if (!("data" in v) || !Array.isArray(v.data)) return false;
  return true;
}

function isErrorPayload(v: unknown): v is { error: string } {
  if (typeof v !== "object" || v === null) return false;
  if (!("error" in v) || typeof v.error !== "string") return false;
  return true;
}
