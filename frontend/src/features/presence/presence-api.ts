import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasStringProp } from "@/lib/type-guards";

/** Story 52.2: a stretch of the event during which the member is there. No window: there the whole event. */
export type PresenceWindow = { startsAt: string; endsAt: string };
export type Presence = { windows: PresenceWindow[]; editable: boolean; eventStartsAt: string; eventEndsAt: string };
export type PresenceRegistrant = { registrationId: string; userId: string; displayName: string; windows: PresenceWindow[] };
export type PresenceTimeline = { eventStartsAt: string; eventEndsAt: string; registrants: PresenceRegistrant[] };

export function presenceKey(registrationId: string): readonly ["presence", string] {
  return ["presence", registrationId];
}

export function adminPresenceKey(eventId: string, registrationId: string): readonly ["admin-presence", string, string] {
  return ["admin-presence", eventId, registrationId];
}

export function presenceTimelineKey(eventId: string): readonly ["presence-timeline", string] {
  return ["presence-timeline", eventId];
}

function isObject(v: unknown): v is object {
  return typeof v === "object" && v !== null;
}

export function isPresenceWindow(v: unknown): v is PresenceWindow {
  return isObject(v) && hasStringProp(v, "startsAt") && hasStringProp(v, "endsAt");
}

function hasWindows(v: object): v is { windows: PresenceWindow[] } {
  return "windows" in v && Array.isArray(v.windows) && v.windows.every(isPresenceWindow);
}

export function isPresence(v: unknown): v is Presence {
  return isObject(v) && hasWindows(v) && hasBooleanProp(v, "editable") && hasStringProp(v, "eventStartsAt") && hasStringProp(v, "eventEndsAt");
}

export function isPresenceRegistrant(v: unknown): v is PresenceRegistrant {
  return isObject(v) && hasStringProp(v, "registrationId") && hasStringProp(v, "userId") && hasStringProp(v, "displayName") && hasWindows(v);
}

export function isPresenceTimeline(v: unknown): v is PresenceTimeline {
  return (
    isObject(v) &&
    hasStringProp(v, "eventStartsAt") &&
    hasStringProp(v, "eventEndsAt") &&
    "registrants" in v &&
    Array.isArray(v.registrants) &&
    v.registrants.every(isPresenceRegistrant)
  );
}

async function readData<T>(url: string, guard: (v: unknown) => v is T): Promise<T | null> {
  try {
    const response = await apiFetch(url);
    if (!response.ok) return null;
    const payload: unknown = await response.json();
    if (!isObject(payload) || !("data" in payload)) return null;
    return guard(payload.data) ? payload.data : null;
  } catch {
    return null;
  }
}

/** Null when signed out, not the member's registration, or unreadable: the card then stays hidden. */
export function fetchPresence(registrationId: string): Promise<Presence | null> {
  return readData(`${env.apiBaseUrl}/registrations/${registrationId}/presence`, isPresence);
}

export function fetchAdminPresence(eventId: string, registrationId: string): Promise<Presence | null> {
  return readData(`${env.apiBaseUrl}/admin/events/${eventId}/registrations/${registrationId}/presence`, isPresence);
}

export function fetchPresenceTimeline(eventId: string): Promise<PresenceTimeline | null> {
  return readData(`${env.apiBaseUrl}/admin/events/${eventId}/presence`, isPresenceTimeline);
}

async function put(url: string, windows: PresenceWindow[]): Promise<string | null> {
  try {
    const response = await apiFetch(url, {
      body: JSON.stringify({ windows }),
      headers: { "Content-Type": "application/json" },
      method: "PUT",
    });
    if (response.ok) return null;
    const payload: unknown = await response.json().catch(() => null);
    const message =
      isObject(payload) && "error" in payload && isObject(payload.error) && hasStringProp(payload.error, "message") ? payload.error.message : null;
    return message ?? "Les plages n'ont pas pu être enregistrées.";
  } catch {
    return "Le serveur est injoignable.";
  }
}

/** Returns null on success, otherwise the message to show. */
export function savePresence(registrationId: string, windows: PresenceWindow[]): Promise<string | null> {
  return put(`${env.apiBaseUrl}/registrations/${registrationId}/presence`, windows);
}

export function saveAdminPresence(eventId: string, registrationId: string, windows: PresenceWindow[]): Promise<string | null> {
  return put(`${env.apiBaseUrl}/admin/events/${eventId}/registrations/${registrationId}/presence`, windows);
}

/** An ISO date as a `datetime-local` value, in the browser's time zone. */
export function toLocalInput(iso: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "";
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** A `datetime-local` value as ISO 8601, or null when empty or invalid. */
export function fromLocalInput(value: string): string | null {
  if (value === "") return null;
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

const DATE_TIME = new Intl.DateTimeFormat("fr-FR", { weekday: "long", day: "numeric", month: "long", hour: "2-digit", minute: "2-digit", timeZone: "Europe/Paris" });
const TIME = new Intl.DateTimeFormat("fr-FR", { hour: "2-digit", minute: "2-digit", timeZone: "Europe/Paris" });
const DAY = new Intl.DateTimeFormat("fr-FR", { timeZone: "Europe/Paris", year: "numeric", month: "2-digit", day: "2-digit" });

/** "vendredi 4 juin à 18:30 → dimanche 6 juin à 12:00", or "... → 23:00" within one day (Europe/Paris). */
export function describeWindow(window: PresenceWindow): string {
  const start = new Date(window.startsAt);
  const end = new Date(window.endsAt);
  const sameDay = DAY.format(start) === DAY.format(end);
  return `${DATE_TIME.format(start)} → ${sameDay ? TIME.format(end) : DATE_TIME.format(end)}`;
}
