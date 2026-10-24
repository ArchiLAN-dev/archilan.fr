import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { requiredFilesForm } from "@/features/games/required-file-check";
import { asGenerationFiles, type GenerationFile } from "@/features/games/required-files";
import { hasBooleanProp, hasStringProp } from "@/lib/type-guards";

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
  /** Last launch: moves on every relaunch, crash restart or resume (story 32.24). */
  startedAt: string | null;
  /** First launch, never moved afterwards. Absent from an API that predates story 32.24. */
  firstLaunchedAt?: string | null;
  stoppedAt: string | null;
  finishedAt?: string | null;
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

/** Story 17.33: the containers of a session the orchestrator reads the logs of. */
export type SessionContainer = "ap" | "bridge" | "gen";

export type ContainerLogs = { container: string; found: boolean; logs: string };

/** Distinct failures so the panel can tell an old orchestrator from a network error. */
export type ContainerLogsResult =
  | { kind: "ok"; data: ContainerLogs }
  | { kind: "unavailable" }
  | { kind: "error" };

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

function isLogsPayload(v: unknown): v is { data: ContainerLogs } {
  if (typeof v !== "object" || v === null) return false;
  if (!("data" in v) || typeof v.data !== "object" || v.data === null) return false;
  return hasStringProp(v.data, "container") && hasBooleanProp(v.data, "found") && hasStringProp(v.data, "logs");
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
  | { kind: "ready"; session: Session; slots: SessionSlot[]; generationFiles: GenerationFile[] }
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
    // Story 44.3/44.5: the files the session's games may read from the admin at generation.
    const generationFiles = "generationFiles" in payload.data ? asGenerationFiles(payload.data.generationFiles) : [];
    return { kind: "ready", session: payload.data.session, slots: payload.data.slots, generationFiles };
  } catch {
    return { kind: "error", message: "Impossible de contacter l'API." };
  }
}

/**
 * Story 44.5: start the generation of an event session, with the files its games read (a ROM) when any.
 * The refusal reason is returned: the admin page used to drop it silently.
 */
export async function generateSession(
  sessionId: string,
  files: Record<string, File> = {},
): Promise<{ ok: true; session: Session } | { ok: false; message: string }> {
  try {
    const body = Object.keys(files).length > 0 ? requiredFilesForm(files) : undefined;
    const res = await apiFetch(`${env.apiBaseUrl}/admin/sessions/${sessionId}/generate`, { method: "POST", body });
    const payload: unknown = await res.json().catch(() => null);
    if (res.ok && typeof payload === "object" && payload !== null && "data" in payload && isSessionItem(payload.data)) {
      return { ok: true, session: payload.data };
    }
    return { ok: false, message: generateErrorMessage(payload) };
  } catch {
    return { ok: false, message: "Impossible de contacter l'API." };
  }
}

function generateErrorMessage(payload: unknown): string {
  if (typeof payload === "object" && payload !== null && "error" in payload) {
    const error = payload.error;
    if (typeof error === "object" && error !== null) {
      if ("details" in error && typeof error.details === "object" && error.details !== null) {
        for (const value of Object.values(error.details)) {
          if (Array.isArray(value) && typeof value[0] === "string") return value[0];
        }
      }
      if ("message" in error && typeof error.message === "string") return error.message;
    }
  }
  return "La génération n'a pas pu être lancée.";
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

export type ForceLaunchResult = { success: boolean; output: string };

function errorMessageOf(payload: unknown): string | null {
  if (typeof payload !== "object" || payload === null || !("error" in payload)) return null;
  const err = payload.error;
  if (typeof err === "string") return err;
  return typeof err === "object" && err !== null && hasStringProp(err, "message") ? err.message : null;
}

/** Relaunch the session through the orchestrator whatever its state, with what the API said about it. */
export async function forceLaunchSession(sessionId: string): Promise<ForceLaunchResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/sessions/${sessionId}/force-launch`, { method: "POST" });
    const payload: unknown = await res.json().catch(() => null);
    const status = typeof payload === "object" && payload !== null && "data" in payload && typeof payload.data === "object" && payload.data !== null && hasStringProp(payload.data, "status") ? payload.data.status : null;
    return { success: res.ok, output: errorMessageOf(payload) ?? status ?? (res.ok ? "Lancement en cours…" : "Erreur inconnue.") };
  } catch {
    return { success: false, output: "Erreur réseau." };
  }
}

/** Story 17.33: the last lines of one container, read by the orchestrator. A 503 is an orchestrator that cannot. */
export async function fetchContainerLogs(sessionId: string, container: SessionContainer): Promise<ContainerLogsResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/sessions/${sessionId}/logs?container=${container}`);
    if (res.status === 503) return { kind: "unavailable" };
    if (!res.ok) return { kind: "error" };
    const payload: unknown = await res.json();
    if (!isLogsPayload(payload)) return { kind: "error" };
    const { container: name, found, logs } = payload.data;
    return { kind: "ok", data: { container: name, found, logs } };
  } catch {
    return { kind: "error" };
  }
}
