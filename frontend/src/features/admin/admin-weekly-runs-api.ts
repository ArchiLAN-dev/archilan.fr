import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

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

export type AdminCurrentWeeklyRun = {
  weeklyRunId: string;
  templateName: string | null;
  gameName: string;
  status: "active" | "finished";
  seed: string;
  startedAt: string | null;
  finishedAt: string | null;
  entryCount: number;
  entries: AdminWeeklyRunEntry[];
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
  defaultYaml?: string | null;
};

// ── Fetch functions ────────────────────────────────────────────────────────────

export async function fetchAdminGameOptions(): Promise<AdminGameOption[]> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/games`);
    if (!res.ok) return [];
    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload) || !Array.isArray(payload.data)) {
      return [];
    }
    const rawItems: unknown[] = payload.data;
    return rawItems
      .filter((g): g is { id: string; name: string; isApworldReady: boolean } => {
        if (typeof g !== "object" || g === null) return false;
        if (!("id" in g) || typeof g.id !== "string") return false;
        if (!("name" in g) || typeof g.name !== "string") return false;
        if (!("isApworldReady" in g) || typeof g.isApworldReady !== "boolean") return false;
        return true;
      })
      .filter((g) => g.isApworldReady)
      .map((g) => ({ id: g.id, name: g.name, isApworldReady: g.isApworldReady }));
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
