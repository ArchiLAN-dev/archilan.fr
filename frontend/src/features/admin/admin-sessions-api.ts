import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasStringProp } from "@/lib/type-guards";

// ── Types ────────────────────────────────────────────────────────────────────

export type SessionStatus =
  | "draft"
  | "validating"
  | "ready"
  | "generating"
  | "generated"
  | "launching"
  | "running"
  | "idle"
  | "restarting"
  | "stopped"
  | "failed"
  | "crashed"
  | "finished";

export type ValidationError = {
  slotName: string;
  errors: string[];
};

export type Session = {
  id: string;
  eventId: string;
  status: SessionStatus;
  host: string | null;
  port: number | null;
  password: string | null;
  serverPassword?: string | null;
  createdAt: string;
  startedAt: string | null;
  stoppedAt: string | null;
  lastActivityAt?: string | null;
  pausedWithoutSave?: boolean;
  error?: string | null;
  lastLogs?: string | null;
  validationErrors?: ValidationError[] | null;
};

export type SessionSlot = {
  id: string;
  sessionId: string;
  registrationId: string;
  gameId: string;
  slotName: string;
  slotOrder: number;
  slotId: string | null;
};

export type ContainerState = {
  found: boolean;
  status: string;
  running: boolean;
  paused: boolean;
  restarting: boolean;
  exit_code: number | null;
  error: string;
  started_at: string | null;
  finished_at: string | null;
};

// ── Guards ───────────────────────────────────────────────────────────────────

// `id` + `status` are the stable discriminants of every session payload; the refinement to the full
// Session shape is a documented trust decision - the admin sessions endpoints are the single
// publisher and always send the complete payload.
function isSessionItem(v: unknown): v is Session {
  if (typeof v !== "object" || v === null) return false;
  return hasStringProp(v, "id") && hasStringProp(v, "status") && hasStringProp(v, "createdAt");
}

function isSessionsPayload(v: unknown): v is { data: Session[] } {
  if (typeof v !== "object" || v === null) return false;
  if (!("data" in v) || !Array.isArray(v.data)) return false;
  return v.data.every(isSessionItem);
}

function isSessionDetailPayload(v: unknown): v is { data: { session: Session; slots: SessionSlot[] } } {
  if (typeof v !== "object" || v === null) return false;
  if (!("data" in v) || typeof v.data !== "object" || v.data === null) return false;
  if (!("session" in v.data) || !isSessionItem(v.data.session)) return false;
  return "slots" in v.data && Array.isArray(v.data.slots);
}

function isEventTitlePayload(v: unknown): v is { data: { title: string } } {
  if (typeof v !== "object" || v === null) return false;
  if (!("data" in v) || typeof v.data !== "object" || v.data === null) return false;
  return hasStringProp(v.data, "title");
}

function isContainerState(v: unknown): v is ContainerState {
  if (typeof v !== "object" || v === null) return false;
  return (
    hasBooleanProp(v, "found") &&
    hasStringProp(v, "status") &&
    hasBooleanProp(v, "running") &&
    hasBooleanProp(v, "paused") &&
    hasBooleanProp(v, "restarting") &&
    "exit_code" in v && (v.exit_code === null || typeof v.exit_code === "number") &&
    hasStringProp(v, "error") &&
    hasNullableStringProp(v, "started_at") &&
    hasNullableStringProp(v, "finished_at")
  );
}

function isLogsPayload(v: unknown): v is { data: { logs: string } } {
  if (typeof v !== "object" || v === null) return false;
  if (!("data" in v) || typeof v.data !== "object" || v.data === null) return false;
  return hasStringProp(v.data, "logs");
}

// ── Fetches ──────────────────────────────────────────────────────────────────

// Discriminated result (instead of the usual `T | null`) so the page keeps its distinct error
// messages (denied / load failure / network failure). Never throws.
export type AdminEventSessionsResult =
  | { kind: "ready"; sessions: Session[] }
  | { kind: "error"; message: string };

export async function fetchAdminEventSessions(eventId: string): Promise<AdminEventSessionsResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/events/${eventId}/sessions`);
    if (res.status === 401 || res.status === 403) {
      return { kind: "error", message: "Accès réservé aux admins ArchiLAN." };
    }
    if (!res.ok) {
      return { kind: "error", message: "Impossible de charger les sessions." };
    }
    const payload: unknown = await res.json();
    if (!isSessionsPayload(payload)) {
      return { kind: "error", message: "Impossible de charger les sessions." };
    }
    return { kind: "ready", sessions: payload.data };
  } catch {
    return { kind: "error", message: "Impossible de contacter l'API." };
  }
}

export type AdminSessionDetailResult =
  | { kind: "ready"; session: Session; slots: SessionSlot[] }
  | { kind: "error"; message: string };

export async function fetchAdminSessionDetail(sessionId: string): Promise<AdminSessionDetailResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/sessions/${sessionId}`);
    if (!res.ok) {
      return { kind: "error", message: "Session introuvable." };
    }
    const payload: unknown = await res.json();
    if (!isSessionDetailPayload(payload)) {
      return { kind: "error", message: "Session introuvable." };
    }
    return { kind: "ready", session: payload.data.session, slots: payload.data.slots };
  } catch {
    return { kind: "error", message: "Impossible de contacter l'API." };
  }
}

export async function fetchAdminEventTitle(eventId: string): Promise<string | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/events/${eventId}`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    if (!isEventTitlePayload(payload) || payload.data.title === "") return null;
    return payload.data.title;
  } catch {
    return null;
  }
}

export async function fetchContainerState(sessionId: string): Promise<ContainerState | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/sessions/${sessionId}/container`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload) || !isContainerState(payload.data)) {
      return null;
    }
    return payload.data;
  } catch {
    return null;
  }
}

export async function fetchContainerLogs(sessionId: string): Promise<string | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/sessions/${sessionId}/logs`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    if (!isLogsPayload(payload)) return null;
    return payload.data.logs;
  } catch {
    return null;
  }
}
