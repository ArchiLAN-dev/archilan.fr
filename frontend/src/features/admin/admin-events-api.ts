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
