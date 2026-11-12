import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import type { AdminEventFormData } from "./admin-event-form";

// ─── Types ─────────────────────────────────────────────────────────────────────

export type AdminEvent = {
  id: string;
  title: string;
  description: string;
  coverImageUrl: string | null;
  photoGallery: string[];
  status: "draft" | "published" | "in-progress" | "completed";
  startsAt: string;
  endsAt: string;
  venue: string;
  capacity: number;
  confirmedRegistrations: number;
  isAtCapacity: boolean;
  registrationOpensAt: string;
  registrationClosesAt: string;
  isPublic: boolean;
  visibility: "public" | "private";
  hasPrivateAccessPassword: boolean;
  gameSelectionEnabled: boolean;
  vodUrl: string | null;
  recapPostSlug: string | null;
  hasRecap: boolean;
  /** Story 3.15: the event featured on the home page (one at most). */
  featuredOnHome: boolean;
  createdAt: string;
  updatedAt: string;
};

// Discriminated result: 401/403 keep the dashboard's dedicated "denied" screen, other failures
// keep their distinct French messages. Never throws (AC-API2) - the old effect was one-shot too.
export type AdminEventsResult =
  | { kind: "ready"; events: AdminEvent[] }
  | { kind: "denied"; message: string }
  | { kind: "error"; message: string };

export type AdminEventEditResult =
  | { kind: "ready"; event: AdminEventFormData }
  | { kind: "not-found" }
  | { kind: "error"; message: string };

export type AdminEventRecapResult =
  | { ok: true }
  | { ok: false; reason: "http"; details: Record<string, string> }
  | { ok: false; reason: "network" };

export type AvailableGame = {
  id: string;
  name: string;
  slug: string;
  availability: string;
  isApworldReady: boolean;
  coverImageUrl: string | null;
  platforms: string[];
};

export type GameSelectionEntry = {
  gameId: string;
  gameName: string;
  gameSlug: string;
};

export type GameSelectionConfig = {
  gameSelectionEnabled: boolean;
  gameSelectionMax: number | null;
  selectedGames: GameSelectionEntry[];
  availableGames: AvailableGame[];
};

export type GameSelectionConfigResult =
  | { kind: "ready"; config: GameSelectionConfig }
  | { kind: "error"; message: string };

// ─── Fetch functions ────────────────────────────────────────────────────────────

export async function fetchAdminEvents(): Promise<AdminEventsResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/events`);

    if (res.status === 401 || res.status === 403) {
      return { kind: "denied", message: "Accès réservé aux admins ArchiLAN." };
    }
    if (!res.ok) {
      return { kind: "error", message: "Impossible de charger les événements." };
    }

    const payload: unknown = await res.json();
    return { kind: "ready", events: isEventListPayload(payload) ? payload.data : [] };
  } catch {
    return { kind: "error", message: "Impossible de contacter l'API événements." };
  }
}

export async function fetchAdminEventForEdit(eventId: string): Promise<AdminEventEditResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/events/${eventId}`);

    if (res.status === 404) {
      return { kind: "not-found" };
    }
    if (res.status === 401 || res.status === 403) {
      return { kind: "error", message: "Accès réservé aux admins ArchiLAN." };
    }
    if (!res.ok) {
      return { kind: "error", message: "Impossible de charger l'événement." };
    }

    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload) || !isAdminEventFormData(payload.data)) {
      return { kind: "error", message: "Réponse API invalide." };
    }

    return { kind: "ready", event: payload.data };
  } catch {
    return { kind: "error", message: "Impossible de contacter l'API." };
  }
}

// Title-only read of a single admin event (used next to the game-selection config; the
// title is decorative there, so any failure quietly degrades to null).
export async function fetchAdminEventTitle(eventId: string): Promise<string | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/events/${eventId}`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload)) return null;
    const data: unknown = payload.data;
    if (typeof data !== "object" || data === null || !("title" in data) || typeof data.title !== "string") {
      return null;
    }
    return data.title;
  } catch {
    return null;
  }
}

export async function fetchAdminEventGameSelection(eventId: string): Promise<GameSelectionConfigResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/events/${eventId}/game-selection`);
    if (!res.ok) {
      return { kind: "error", message: "Impossible de charger la configuration de sélection." };
    }
    const payload: unknown = await res.json();
    if (!isGameSelectionConfigPayload(payload)) {
      return { kind: "error", message: "Réponse API invalide." };
    }
    return { kind: "ready", config: payload.data };
  } catch {
    return { kind: "error", message: "Impossible de contacter l'API." };
  }
}

// ─── Mutations ─────────────────────────────────────────────────────────────────

export async function updateAdminEventStatus(
  eventId: string,
  status: AdminEvent["status"],
): Promise<AdminEvent | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/events/${eventId}/status`, {
      body: JSON.stringify({ status }),
      headers: { "Content-Type": "application/json" },
      method: "PATCH",
    });
    const payload: unknown = await res.json();
    if (!res.ok || !isEventPayload(payload)) return null;
    return payload.data;
  } catch {
    return null;
  }
}

export async function updateAdminEventPrivateAccess(
  eventId: string,
  password: string,
): Promise<AdminEvent | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/events/${eventId}/private-access`, {
      body: JSON.stringify({ password }),
      headers: { "Content-Type": "application/json" },
      method: "PATCH",
    });
    const payload: unknown = await res.json();
    if (!res.ok || !isEventPayload(payload)) return null;
    return payload.data;
  } catch {
    return null;
  }
}

export async function saveAdminEventRecap(
  eventId: string,
  vodUrl: string | null,
  recapPostSlug: string | null,
): Promise<AdminEventRecapResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/events/${eventId}/recap`, {
      body: JSON.stringify({ vodUrl, recapPostSlug }),
      headers: { "Content-Type": "application/json" },
      method: "PATCH",
    });
    const payload: unknown = await res.json();
    if (!res.ok) {
      return { ok: false, reason: "http", details: extractRecapDetails(payload) };
    }
    return { ok: true };
  } catch {
    return { ok: false, reason: "network" };
  }
}

/** Story 3.15: features the event on the home page, or removes it. Returns null on success, else the message. */
export async function featureAdminEventOnHome(eventId: string, featured: boolean): Promise<string | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/events/${eventId}/featured`, {
      body: JSON.stringify({ featured }),
      headers: { "Content-Type": "application/json" },
      method: "PUT",
    });
    if (res.ok) return null;
    const payload: unknown = await res.json().catch(() => null);
    return featuredErrorMessage(payload);
  } catch {
    return "Le serveur est injoignable.";
  }
}

function featuredErrorMessage(payload: unknown): string {
  const fallback = "La mise en avant n'a pas pu être enregistrée.";
  if (typeof payload !== "object" || payload === null || !("error" in payload)) return fallback;
  const error: unknown = payload.error;
  if (typeof error !== "object" || error === null) return fallback;
  const details: unknown = "details" in error ? error.details : null;
  if (typeof details === "object" && details !== null && "featured" in details && Array.isArray(details.featured) && typeof details.featured[0] === "string") {
    return details.featured[0];
  }
  return "message" in error && typeof error.message === "string" ? error.message : fallback;
}

// ─── Type guards & helpers ─────────────────────────────────────────────────────

function isEventListPayload(payload: unknown): payload is { data: AdminEvent[] } {
  if (typeof payload !== "object" || payload === null || !("data" in payload)) return false;
  return Array.isArray(payload.data);
}

export function isEventPayload(payload: unknown): payload is { data: AdminEvent } {
  if (typeof payload !== "object" || payload === null || !("data" in payload)) return false;
  const data: unknown = payload.data;
  if (typeof data !== "object" || data === null) return false;
  return "id" in data && "title" in data && "gameSelectionEnabled" in data && "hasRecap" in data;
}

// Full-shape guard replacing the former `as AdminEventFormData` cast (story 33.7 C4).
function isAdminEventFormData(v: unknown): v is AdminEventFormData {
  if (typeof v !== "object" || v === null) return false;
  if (!("id" in v) || typeof v.id !== "string") return false;
  if (!("title" in v) || typeof v.title !== "string") return false;
  if (!("description" in v) || typeof v.description !== "string") return false;
  if (!("coverImageUrl" in v) || (v.coverImageUrl !== null && typeof v.coverImageUrl !== "string")) return false;
  if (!("coverImageKey" in v) || (v.coverImageKey !== null && typeof v.coverImageKey !== "string")) return false;
  if (!("photoGallery" in v) || !Array.isArray(v.photoGallery) || !v.photoGallery.every((p) => typeof p === "string")) return false;
  if (!("startsAt" in v) || typeof v.startsAt !== "string") return false;
  if (!("endsAt" in v) || typeof v.endsAt !== "string") return false;
  if (!("venue" in v) || typeof v.venue !== "string") return false;
  if (!("capacity" in v) || typeof v.capacity !== "number") return false;
  if (!("confirmedRegistrations" in v) || typeof v.confirmedRegistrations !== "number") return false;
  if (!("registrationOpensAt" in v) || typeof v.registrationOpensAt !== "string") return false;
  if (!("registrationClosesAt" in v) || typeof v.registrationClosesAt !== "string") return false;
  if (!("helloassoFormSlug" in v) || (v.helloassoFormSlug !== null && typeof v.helloassoFormSlug !== "string")) return false;
  if ("practicalInfo" in v && v.practicalInfo !== null && typeof v.practicalInfo !== "string") return false;
  if ("eveReminderEnabled" in v && typeof v.eveReminderEnabled !== "boolean") return false;
  if ("mediaServerHost" in v && v.mediaServerHost !== null && typeof v.mediaServerHost !== "string") return false;
  return "isPublic" in v && typeof v.isPublic === "boolean";
}

function isGameSelectionConfigPayload(payload: unknown): payload is { data: GameSelectionConfig } {
  if (typeof payload !== "object" || payload === null || !("data" in payload)) return false;
  const data: unknown = payload.data;
  if (typeof data !== "object" || data === null) return false;
  return "gameSelectionEnabled" in data && "selectedGames" in data && "availableGames" in data;
}

function extractRecapDetails(payload: unknown): Record<string, string> {
  if (typeof payload !== "object" || payload === null || !("error" in payload)) return {};
  const err: unknown = payload.error;
  if (typeof err !== "object" || err === null || !("details" in err)) return {};
  const details: unknown = err.details;
  if (typeof details !== "object" || details === null) return {};

  const result: Record<string, string> = {};
  for (const [key, value] of Object.entries(details)) {
    if (Array.isArray(value) && typeof value[0] === "string") {
      result[key] = value[0];
    }
  }
  return result;
}

/** Story 47.6: what the régie agent of an event needs, read by an admin. */
export type MediaAgentConfig = { eventId: string; secret: string };

export function isMediaAgentConfig(v: unknown): v is MediaAgentConfig {
  return typeof v === "object" && v !== null && "eventId" in v && typeof v.eventId === "string" && "secret" in v && typeof v.secret === "string";
}

export async function fetchAdminMediaAgent(eventId: string): Promise<MediaAgentConfig | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/events/${encodeURIComponent(eventId)}/media-agent`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload)) return null;
    return isMediaAgentConfig(payload.data) ? payload.data : null;
  } catch {
    return null;
  }
}

/** The lines to paste in `ops/lan-media/.env`: the agent calls the site root, not the `/api/v1` base. */
export function mediaAgentEnvLines(apiBaseUrl: string, config: MediaAgentConfig): string {
  const siteRoot = apiBaseUrl.replace(/\/+$/, "").replace(/\/api\/v1$/, "");
  return [`ARCHILAN_API_URL=${siteRoot}`, `ARCHILAN_EVENT_ID=${config.eventId}`, `ARCHILAN_AGENT_SECRET=${config.secret}`].join("\n");
}

/** Story 47.9: how the local broadcasting of an event goes on the day, durations computed by the API. */
export type AdminLocalStream = { registrationId: string; live: boolean; cutMinutesAgo: number | null };
export type AdminLocalStreams = {
  mediaServerHost: string | null;
  /** v0.66.1: opens the LAN screens of the régie screen; null without a media server. */
  regieToken: string | null;
  agentSeenSecondsAgo: number | null;
  liveCount: number;
  streams: AdminLocalStream[];
};

/** Past this silence, the régie agent counts as stopped (the API's streams expire after 30 s too). */
export const AGENT_SILENT_AFTER_SECONDS = 30;

/** Story 47.9: the agent reports every 10 s; the admin screens follow it at this pace. */
export const LOCAL_STREAMS_REFRESH_MS = 15_000;

/**
 * v0.66.1: refetched only for an event with a media server; for the others (nearly all), one read is enough. Takes the
 * last data TanStack Query holds.
 */
export function localStreamsRefetchInterval(view: AdminLocalStreams | null | undefined): number | false {
  return view?.mediaServerHost ? LOCAL_STREAMS_REFRESH_MS : false;
}

function isAdminLocalStream(v: unknown): v is AdminLocalStream {
  return (
    typeof v === "object" &&
    v !== null &&
    "registrationId" in v &&
    typeof v.registrationId === "string" &&
    "live" in v &&
    typeof v.live === "boolean" &&
    "cutMinutesAgo" in v &&
    (v.cutMinutesAgo === null || typeof v.cutMinutesAgo === "number")
  );
}

export function isAdminLocalStreams(v: unknown): v is AdminLocalStreams {
  return (
    typeof v === "object" &&
    v !== null &&
    "mediaServerHost" in v &&
    (v.mediaServerHost === null || typeof v.mediaServerHost === "string") &&
    "regieToken" in v &&
    (v.regieToken === null || typeof v.regieToken === "string") &&
    "agentSeenSecondsAgo" in v &&
    (v.agentSeenSecondsAgo === null || typeof v.agentSeenSecondsAgo === "number") &&
    "liveCount" in v &&
    typeof v.liveCount === "number" &&
    "streams" in v &&
    Array.isArray(v.streams) &&
    v.streams.every(isAdminLocalStream)
  );
}

export async function fetchAdminLocalStreams(eventId: string): Promise<AdminLocalStreams | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/events/${encodeURIComponent(eventId)}/local-streams`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload)) return null;
    return isAdminLocalStreams(payload.data) ? payload.data : null;
  } catch {
    return null;
  }
}

/** Story 47.9: the hub's "Serveur média" line. */
export function mediaServerHubState(view: AdminLocalStreams | null): string | undefined {
  if (view === null) return undefined;
  if (view.mediaServerHost === null) return "Non configuré";
  const seen = view.agentSeenSecondsAgo;
  if (seen === null) return "Agent jamais vu";
  if (seen <= AGENT_SILENT_AFTER_SECONDS) return `Agent vu il y a ${seen} s - ${view.liveCount} en direct`;
  return `Agent muet depuis ${Math.max(1, Math.floor(seen / 60))} min`;
}

/** Story 47.9: a registrant's "Diffusion locale" cell, "Jamais" when they never sent anything. */
export function localStreamLabel(stream: AdminLocalStream | undefined): string {
  if (stream === undefined) return "Jamais";
  if (stream.live) return "En direct";
  return stream.cutMinutesAgo !== null && stream.cutMinutesAgo >= 1 ? `Coupé il y a ${stream.cutMinutesAgo} min` : "Coupé à l'instant";
}
