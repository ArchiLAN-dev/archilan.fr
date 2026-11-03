import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

/**
 * Story 49.1: an event's link to Minimapperz, admin side. The API key is written, never read back: the server only
 * says whether one is set and how it ends. Every function returns a value instead of throwing (AC-API2).
 */
/** Story 49.3: how the last collection of the games went (status null before the first pass). */
export type MinimapperzCollectionState = { at: string | null; status: string | null; games: number; credited: number; until: string | null };

export type MinimapperzSettings = {
  enabled: boolean;
  slug: string | null;
  apiKeySet: boolean;
  apiKeyEnd: string | null;
  pellesPerGame: number;
  pellesPerWin: number;
  capPerPlayer: number;
  collection: MinimapperzCollectionState;
};

/** What the form sends: `apiKey` empty keeps the key on file, `clearApiKey` removes it. */
export type MinimapperzSettingsInput = {
  enabled: boolean;
  slug: string;
  apiKey: string;
  clearApiKey: boolean;
  pellesPerGame: number;
  pellesPerWin: number;
  capPerPlayer: number;
};

export type MinimapperzEvent = { slug: string; name: string; opensAt: string; closesAt: string; isOpen: boolean };

export type MinimapperzCheck =
  | { status: "ok"; event: MinimapperzEvent }
  | { status: "not_configured" | "unauthorized" | "unreachable"; event: null };

export type SaveMinimapperzResult = { kind: "ok"; settings: MinimapperzSettings } | { kind: "error"; message: string; fields: Record<string, string> };

const CHECK_STATUSES = ["not_configured", "unauthorized", "unreachable"] as const;

export function isMinimapperzSettings(v: unknown): v is MinimapperzSettings {
  return (
    typeof v === "object" &&
    v !== null &&
    hasBooleanProp(v, "enabled") &&
    hasNullableStringProp(v, "slug") &&
    hasBooleanProp(v, "apiKeySet") &&
    hasNullableStringProp(v, "apiKeyEnd") &&
    hasNumberProp(v, "pellesPerGame") &&
    hasNumberProp(v, "pellesPerWin") &&
    hasNumberProp(v, "capPerPlayer") &&
    "collection" in v &&
    isMinimapperzCollectionState(v.collection)
  );
}

function isMinimapperzCollectionState(v: unknown): v is MinimapperzCollectionState {
  return (
    typeof v === "object" &&
    v !== null &&
    hasNullableStringProp(v, "at") &&
    hasNullableStringProp(v, "status") &&
    hasNumberProp(v, "games") &&
    hasNumberProp(v, "credited") &&
    hasNullableStringProp(v, "until")
  );
}

function isMinimapperzEvent(v: unknown): v is MinimapperzEvent {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "slug") &&
    hasStringProp(v, "name") &&
    hasStringProp(v, "opensAt") &&
    hasStringProp(v, "closesAt") &&
    hasBooleanProp(v, "isOpen")
  );
}

export function isMinimapperzCheck(v: unknown): v is MinimapperzCheck {
  if (typeof v !== "object" || v === null || !hasStringProp(v, "status") || !("event" in v)) return false;
  if (v.status === "ok") return isMinimapperzEvent(v.event);
  return CHECK_STATUSES.some((status) => status === v.status) && v.event === null;
}

function dataOf(payload: unknown): unknown {
  return typeof payload === "object" && payload !== null && "data" in payload ? payload.data : null;
}

function settingsUrl(eventId: string): string {
  return `${env.apiBaseUrl}/admin/events/${encodeURIComponent(eventId)}/minimapperz`;
}

export async function fetchMinimapperzSettings(eventId: string): Promise<MinimapperzSettings | null> {
  try {
    const res = await apiFetch(settingsUrl(eventId));
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return isMinimapperzSettings(data) ? data : null;
  } catch {
    return null;
  }
}

/** The server owns the bounds; this relays its message and the first error of each field. */
export async function saveMinimapperzSettings(eventId: string, input: MinimapperzSettingsInput): Promise<SaveMinimapperzResult> {
  const failure = "Réglages non enregistrés. Réessaie.";
  try {
    const res = await apiFetch(settingsUrl(eventId), {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(input),
    });
    const payload: unknown = await res.json();
    const data = dataOf(payload);
    if (res.ok && isMinimapperzSettings(data)) return { kind: "ok", settings: data };
    const error = typeof payload === "object" && payload !== null && "error" in payload ? payload.error : null;
    const message = typeof error === "object" && error !== null && hasStringProp(error, "message") ? error.message : null;
    const details = typeof error === "object" && error !== null && "details" in error ? error.details : null;
    return { kind: "error", message: message ?? failure, fields: fieldErrors(details) };
  } catch {
    return { kind: "error", message: failure, fields: {} };
  }
}

function fieldErrors(details: unknown): Record<string, string> {
  if (typeof details !== "object" || details === null) return {};
  const fields: Record<string, string> = {};
  for (const [field, messages] of Object.entries(details)) {
    const first: unknown = Array.isArray(messages) ? messages[0] : null;
    if (typeof first === "string") fields[field] = first;
  }
  return fields;
}

/** Asks the server to open the Minimapperz event with the key on file; null when the server itself fails. */
export async function checkMinimapperzConnection(eventId: string): Promise<MinimapperzCheck | null> {
  try {
    const res = await apiFetch(`${settingsUrl(eventId)}/check`, { method: "POST" });
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return isMinimapperzCheck(data) ? data : null;
  } catch {
    return null;
  }
}

/** Story 49.2: one code of the event, held by a member or free. */
export type MinimapperzCodeRow = { code: string; userId: string | null; displayName: string | null; registered: boolean; assignedAt: string | null };

export type MinimapperzCodes = { imported: number; assigned: number; free: number; registrantsWithoutCode: number; codes: MinimapperzCodeRow[] };

export type MinimapperzCodesAction<T> = { kind: "ok"; result: T } | { kind: "error"; message: string };

export type MinimapperzImport = { added: number; duplicates: number; invalid: string[]; overview: MinimapperzCodes };

export type MinimapperzAssignment = { assigned: number; withoutCode: number; overview: MinimapperzCodes };

/** Story 49.2: a registrant's code and where to type it. */
export type MinimapperzCodeClaim = { code: string; lanUrl: string };

function isMinimapperzCodeRow(v: unknown): v is MinimapperzCodeRow {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "code") &&
    hasNullableStringProp(v, "userId") &&
    hasNullableStringProp(v, "displayName") &&
    hasBooleanProp(v, "registered") &&
    hasNullableStringProp(v, "assignedAt")
  );
}

export function isMinimapperzCodes(v: unknown): v is MinimapperzCodes {
  return (
    typeof v === "object" &&
    v !== null &&
    hasNumberProp(v, "imported") &&
    hasNumberProp(v, "assigned") &&
    hasNumberProp(v, "free") &&
    hasNumberProp(v, "registrantsWithoutCode") &&
    "codes" in v &&
    Array.isArray(v.codes) &&
    v.codes.every(isMinimapperzCodeRow)
  );
}

function isMinimapperzImport(v: unknown): v is MinimapperzImport {
  return (
    typeof v === "object" &&
    v !== null &&
    hasNumberProp(v, "added") &&
    hasNumberProp(v, "duplicates") &&
    "invalid" in v &&
    Array.isArray(v.invalid) &&
    v.invalid.every((line) => typeof line === "string") &&
    "overview" in v &&
    isMinimapperzCodes(v.overview)
  );
}

function isMinimapperzAssignment(v: unknown): v is MinimapperzAssignment {
  return typeof v === "object" && v !== null && hasNumberProp(v, "assigned") && hasNumberProp(v, "withoutCode") && "overview" in v && isMinimapperzCodes(v.overview);
}

export function isMinimapperzCodeClaim(v: unknown): v is MinimapperzCodeClaim {
  return typeof v === "object" && v !== null && hasStringProp(v, "code") && hasStringProp(v, "lanUrl");
}

function codesUrl(eventId: string): string {
  return `${settingsUrl(eventId)}/codes`;
}

export async function fetchMinimapperzCodes(eventId: string): Promise<MinimapperzCodes | null> {
  try {
    const res = await apiFetch(codesUrl(eventId));
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return isMinimapperzCodes(data) ? data : null;
  } catch {
    return null;
  }
}

async function codesAction<T>(url: string, init: RequestInit, guard: (v: unknown) => v is T, failure: string): Promise<MinimapperzCodesAction<T>> {
  try {
    const res = await apiFetch(url, init);
    const payload: unknown = await res.json();
    const data = dataOf(payload);
    if (res.ok && guard(data)) return { kind: "ok", result: data };
    const error = typeof payload === "object" && payload !== null && "error" in payload ? payload.error : null;
    const message = typeof error === "object" && error !== null && hasStringProp(error, "message") ? error.message : null;
    return { kind: "error", message: message ?? failure };
  } catch {
    return { kind: "error", message: failure };
  }
}

export function importMinimapperzCodes(eventId: string, codes: string): Promise<MinimapperzCodesAction<MinimapperzImport>> {
  return codesAction(
    codesUrl(eventId),
    { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ codes }) },
    isMinimapperzImport,
    "Import impossible. Réessaie.",
  );
}

export function assignMinimapperzCodes(eventId: string): Promise<MinimapperzCodesAction<MinimapperzAssignment>> {
  return codesAction(`${codesUrl(eventId)}/assign`, { method: "POST" }, isMinimapperzAssignment, "Attribution impossible. Réessaie.");
}

export function detachMinimapperzCode(eventId: string, code: string): Promise<MinimapperzCodesAction<MinimapperzCodes>> {
  return codesAction(`${codesUrl(eventId)}/${encodeURIComponent(code)}/assignment`, { method: "DELETE" }, isMinimapperzCodes, "Le code n'a pas pu être repris. Réessaie.");
}

/** A registrant's code (the first call hands one out), or null when there is none to show. */
export async function claimMinimapperzCode(eventId: string): Promise<MinimapperzCodeClaim | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/events/${encodeURIComponent(eventId)}/minimapperz/code`, { method: "POST" });
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return isMinimapperzCodeClaim(data) ? data : null;
  } catch {
    return null;
  }
}

/** Story 49.3: a player seen on Minimapperz, and the member their code belongs to (linked = an active registrant). */
export type MinimapperzPlayerRow = { username: string; code: string | null; games: number; wins: number; member: string | null; linked: boolean };

export type MinimapperzPlayers = { status: "ok" | "not_configured" | "unauthorized" | "unreachable"; players: MinimapperzPlayerRow[] };

function isMinimapperzPlayerRow(v: unknown): v is MinimapperzPlayerRow {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "username") &&
    hasNullableStringProp(v, "code") &&
    hasNumberProp(v, "games") &&
    hasNumberProp(v, "wins") &&
    hasNullableStringProp(v, "member") &&
    hasBooleanProp(v, "linked")
  );
}

export function isMinimapperzPlayers(v: unknown): v is MinimapperzPlayers {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "status") &&
    ["ok", "not_configured", "unauthorized", "unreachable"].includes(v.status) &&
    "players" in v &&
    Array.isArray(v.players) &&
    v.players.every(isMinimapperzPlayerRow)
  );
}

export async function fetchMinimapperzPlayers(eventId: string): Promise<MinimapperzPlayers | null> {
  try {
    const res = await apiFetch(`${settingsUrl(eventId)}/players`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return isMinimapperzPlayers(data) ? data : null;
  } catch {
    return null;
  }
}

/** Story 49.4: the public board of an event, for the régie screen. */
export type MinimapperzBoardLine = { rank: number; name: string; score: number; games: number; wins: number };

export type MinimapperzBoardGame = { id: string; playedAt: string; winner: string | null; players: number };

export type MinimapperzBoard = {
  status: "ok" | "unavailable" | "unreachable";
  eventTitle: string;
  ranking: MinimapperzBoardLine[];
  recent: MinimapperzBoardGame[];
};

function isBoardLine(v: unknown): v is MinimapperzBoardLine {
  return typeof v === "object" && v !== null && hasNumberProp(v, "rank") && hasStringProp(v, "name") && hasNumberProp(v, "score") && hasNumberProp(v, "games") && hasNumberProp(v, "wins");
}

function isBoardGame(v: unknown): v is MinimapperzBoardGame {
  return typeof v === "object" && v !== null && hasStringProp(v, "id") && hasStringProp(v, "playedAt") && hasNullableStringProp(v, "winner") && hasNumberProp(v, "players");
}

export function isMinimapperzBoard(v: unknown): v is MinimapperzBoard {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "status") &&
    ["ok", "unavailable", "unreachable"].includes(v.status) &&
    hasStringProp(v, "eventTitle") &&
    "ranking" in v &&
    Array.isArray(v.ranking) &&
    v.ranking.every(isBoardLine) &&
    "recent" in v &&
    Array.isArray(v.recent) &&
    v.recent.every(isBoardGame)
  );
}

export async function fetchMinimapperzBoard(eventId: string): Promise<MinimapperzBoard | null> {
  try {
    const res = await fetch(`${env.apiBaseUrl}/events/${encodeURIComponent(eventId)}/minimapperz/board`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return isMinimapperzBoard(data) ? data : null;
  } catch {
    return null;
  }
}
