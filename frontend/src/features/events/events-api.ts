// Registration-funnel fetch functions (story 33.18). All results are discriminated unions
// ("errors-in-result", same pattern as personal-runs-api's fetchInvitePreview): the fetch
// functions never throw, so components can decide per-query whether an error kind becomes a
// thrown query error (keep-last-data semantics) or terminal data (not_found).
// Every endpoint goes through apiFetch so the 401 refresh-and-retry flow applies uniformly
// (the pre-TanStack eligibility gate used raw fetch and silently lost that behaviour).

import { apiFetch } from "@/lib/apiFetch";
import { asOptionTypesMap, type OptionTypesMap } from "@/lib/archipelago-yaml";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

// ── Shared helpers ────────────────────────────────────────────────────────────

function dataOf(payload: unknown): unknown {
  if (typeof payload !== "object" || payload === null || !("data" in payload)) return null;
  return payload.data;
}

function optionalString(v: object, key: string): string | null {
  const value: unknown = Reflect.get(v, key);
  return typeof value === "string" ? value : null;
}

// ── Auth probe ────────────────────────────────────────────────────────────────

export type AuthProbeResult = "authenticated" | "unauthenticated";

/**
 * Session probe used by the registration-funnel gates before loading protected data.
 * Mirrors the pre-TanStack behaviour exactly: only an explicit 401/403 counts as
 * unauthenticated (any other response, even a 5xx, lets the data fetch proceed and fail on
 * its own terms); `null` means the API could not be reached at all.
 */
export async function fetchAuthProbe(): Promise<AuthProbeResult | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/account/profile`);
    if (res.status === 401 || res.status === 403) return "unauthenticated";
    return "authenticated";
  } catch {
    return null;
  }
}

// ── Registration eligibility ──────────────────────────────────────────────────

export type EligibilityReason =
  | "private_event"
  | "event_completed"
  | "event_in_progress"
  | "registration_not_open_yet"
  | "registration_closed"
  | "capacity_full";

export type EligibilityEvent = {
  id: string;
  title: string;
  startsAt: string;
  endsAt: string;
  venue: string;
  capacity: number;
  confirmedRegistrations: number;
  registrationOpensAt: string;
  registrationClosesAt: string;
  isPublic: boolean;
};

export type EligibilityResult = {
  eligible: boolean;
  reason: EligibilityReason | null;
  opensAt: string | null;
  event: EligibilityEvent;
};

export type RegistrationEligibilityResult =
  | { kind: "success"; result: EligibilityResult }
  | { kind: "not_found" }
  | { kind: "error"; message: string };

// Same (deliberately loose) shape check as the pre-TanStack gate: presence of the two
// discriminant fields is enough, the API is the single producer of this payload.
function isEligibilityResult(v: unknown): v is EligibilityResult {
  return typeof v === "object" && v !== null && "eligible" in v && "event" in v;
}

export async function fetchRegistrationEligibility(
  eventSlug: string,
): Promise<RegistrationEligibilityResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/events/${eventSlug}/registration-eligibility`);
    if (res.status === 404) return { kind: "not_found" };
    if (!res.ok) return { kind: "error", message: "Impossible de vérifier l'éligibilité." };
    const payload: unknown = await res.json();
    const data = dataOf(payload);
    if (!isEligibilityResult(data)) return { kind: "error", message: "Réponse API invalide." };
    return { kind: "success", result: data };
  } catch {
    return { kind: "error", message: "Impossible de contacter l'API." };
  }
}

// ── Game selection (GET /registrations/{id}/game-selection) ──────────────────
// Three views (selection, recap, slot yaml) read the same endpoint but parse it into
// different shapes with their own error strings, so each gets its own fetch function.

export type AvailableGame = {
  id: string;
  name: string;
  slug: string;
  description: string;
  availability: string;
  isApworldReady: boolean;
  defaultYaml: string | null;
  coverImageUrl: string | null;
  coverImageAlt: string | null;
};

export type SelectionSlot = {
  slotId: string;
  gameId: string;
  gameName: string;
};

export type SelectionData = {
  registrationId: string;
  eventId: string;
  eventTitle: string;
  registrationOpen: boolean;
  gameSelectionEnabled: boolean;
  maxGamesPerRegistrant: number | null;
  slots: SelectionSlot[];
  availableGames: AvailableGame[];
};

export type GameSelectionResult =
  | { kind: "success"; data: SelectionData }
  | { kind: "not_found" }
  | { kind: "error"; message: string };

type RawEnvelope =
  | { kind: "success"; payload: unknown }
  | { kind: "not_found" }
  | { kind: "error"; message: string };

async function fetchGameSelectionPayload(
  registrationId: string,
  loadErrorMessage: string,
): Promise<RawEnvelope> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/registrations/${registrationId}/game-selection`);
    if (res.status === 404) return { kind: "not_found" };
    if (!res.ok) return { kind: "error", message: loadErrorMessage };
    const payload: unknown = await res.json();
    return { kind: "success", payload };
  } catch {
    return { kind: "error", message: "Impossible de contacter l'API." };
  }
}

function parseSelectionSlot(v: unknown): SelectionSlot | null {
  if (typeof v !== "object" || v === null) return null;
  if (!hasStringProp(v, "slotId")) return null;
  if (!hasStringProp(v, "gameId")) return null;
  return {
    slotId: v.slotId,
    gameId: v.gameId,
    gameName: hasStringProp(v, "gameName") ? v.gameName : v.gameId,
  };
}

function toAvailableGame(v: unknown): AvailableGame | null {
  if (typeof v !== "object" || v === null) return null;
  if (!hasStringProp(v, "id")) return null;
  if (!hasStringProp(v, "name")) return null;
  if (!hasStringProp(v, "slug")) return null;
  if (!hasStringProp(v, "description")) return null;
  if (!hasStringProp(v, "availability")) return null;
  return {
    id: v.id,
    name: v.name,
    slug: v.slug,
    description: v.description,
    availability: v.availability,
    isApworldReady: Reflect.get(v, "isApworldReady") === true,
    defaultYaml: optionalString(v, "defaultYaml"),
    coverImageUrl: optionalString(v, "coverImageUrl"),
    coverImageAlt: optionalString(v, "coverImageAlt"),
  };
}

function parseSelectionData(payload: unknown): SelectionData | null {
  const d = dataOf(payload);
  if (typeof d !== "object" || d === null) return null;
  if (!hasStringProp(d, "registrationId")) return null;
  if (!hasStringProp(d, "eventId")) return null;
  if (!hasStringProp(d, "eventTitle")) return null;
  if (!hasBooleanProp(d, "gameSelectionEnabled")) return null;
  const rawSlots: unknown = Reflect.get(d, "slots");
  const rawGames: unknown = Reflect.get(d, "availableGames");
  if (!Array.isArray(rawSlots)) return null;
  if (!Array.isArray(rawGames)) return null;
  return {
    registrationId: d.registrationId,
    eventId: d.eventId,
    eventTitle: d.eventTitle,
    registrationOpen: hasBooleanProp(d, "registrationOpen") ? d.registrationOpen : true,
    gameSelectionEnabled: d.gameSelectionEnabled,
    maxGamesPerRegistrant: hasNumberProp(d, "maxGamesPerRegistrant") ? d.maxGamesPerRegistrant : null,
    slots: rawSlots.flatMap((s: unknown) => {
      const slot = parseSelectionSlot(s);
      return slot ? [slot] : [];
    }),
    availableGames: rawGames.flatMap((g: unknown) => {
      const game = toAvailableGame(g);
      return game ? [game] : [];
    }),
  };
}

export async function fetchGameSelection(registrationId: string): Promise<GameSelectionResult> {
  const raw = await fetchGameSelectionPayload(
    registrationId,
    "Impossible de charger la sélection de jeux.",
  );
  if (raw.kind !== "success") return raw;
  const data = parseSelectionData(raw.payload);
  if (!data) return { kind: "error", message: "Réponse API invalide." };
  return { kind: "success", data };
}

// ── Registration recap ────────────────────────────────────────────────────────

export type RecapSlot = {
  slotId: string;
  slotOrder: number;
  gameId: string;
  gameName: string;
  playerYaml: string | null;
  apworldHash: string | null;
};

export type RecapGame = {
  id: string;
  isApworldReady: boolean;
  defaultYaml: string | null;
};

export type RecapData = {
  registrationId: string;
  eventTitle: string;
  gameSelectionEnabled: boolean;
  registrationOpen: boolean;
  slots: RecapSlot[];
  gameMap: Map<string, RecapGame>;
};

export type RegistrationRecapResult =
  | { kind: "success"; data: RecapData }
  | { kind: "not_found" }
  | { kind: "error"; message: string };

function parseRecapSlot(v: unknown): RecapSlot | null {
  if (typeof v !== "object" || v === null) return null;
  if (!hasStringProp(v, "slotId")) return null;
  if (!hasNumberProp(v, "slotOrder")) return null;
  if (!hasStringProp(v, "gameId")) return null;
  if (!hasStringProp(v, "gameName")) return null;
  return {
    slotId: v.slotId,
    slotOrder: v.slotOrder,
    gameId: v.gameId,
    gameName: v.gameName,
    playerYaml: optionalString(v, "playerYaml"),
    apworldHash: optionalString(v, "apworldHash"),
  };
}

function parseRecapGame(v: unknown): RecapGame | null {
  if (typeof v !== "object" || v === null) return null;
  if (!hasStringProp(v, "id")) return null;
  return {
    id: v.id,
    isApworldReady: Reflect.get(v, "isApworldReady") === true,
    defaultYaml: optionalString(v, "defaultYaml"),
  };
}

function parseRecapData(payload: unknown): RecapData | null {
  const d = dataOf(payload);
  if (typeof d !== "object" || d === null) return null;
  if (!hasStringProp(d, "registrationId")) return null;
  if (!hasStringProp(d, "eventTitle")) return null;
  if (!hasBooleanProp(d, "gameSelectionEnabled")) return null;
  const rawSlots: unknown = Reflect.get(d, "slots");
  if (!Array.isArray(rawSlots)) return null;

  const slots = rawSlots.flatMap((s: unknown) => {
    const parsed = parseRecapSlot(s);
    return parsed ? [parsed] : [];
  });

  const gameMap = new Map<string, RecapGame>();
  const rawGames: unknown = Reflect.get(d, "availableGames");
  if (Array.isArray(rawGames)) {
    const games = rawGames.flatMap((g: unknown) => {
      const game = parseRecapGame(g);
      return game ? [game] : [];
    });
    for (const game of games) gameMap.set(game.id, game);
  }

  return {
    registrationId: d.registrationId,
    eventTitle: d.eventTitle,
    gameSelectionEnabled: d.gameSelectionEnabled,
    registrationOpen: hasBooleanProp(d, "registrationOpen") ? d.registrationOpen : true,
    slots,
    gameMap,
  };
}

export async function fetchRegistrationRecap(
  registrationId: string,
): Promise<RegistrationRecapResult> {
  const raw = await fetchGameSelectionPayload(
    registrationId,
    "Impossible de charger le récapitulatif.",
  );
  if (raw.kind !== "success") return raw;
  const data = parseRecapData(raw.payload);
  if (!data) return { kind: "error", message: "Réponse API invalide." };
  return { kind: "success", data };
}

// ── Slot YAML options ─────────────────────────────────────────────────────────

export type SlotYamlSlot = {
  slotId: string;
  slotOrder: number;
  gameId: string;
  gameName: string;
  playerYaml: string | null;
};

export type SlotYamlGame = {
  id: string;
  isApworldReady: boolean;
  defaultYaml: string | null;
  optionTypes: OptionTypesMap | null;
};

export type SlotYamlData = {
  eventTitle: string;
  registrationOpen: boolean;
  slot: SlotYamlSlot;
  game: SlotYamlGame;
  slotLabel: string;
};

export type SlotYamlResult =
  | { kind: "success"; data: SlotYamlData }
  | { kind: "not_found" }
  | { kind: "error"; message: string };

function parseSlotYamlSlot(v: unknown): SlotYamlSlot | null {
  if (typeof v !== "object" || v === null) return null;
  if (!hasStringProp(v, "slotId")) return null;
  if (!hasNumberProp(v, "slotOrder")) return null;
  if (!hasStringProp(v, "gameId")) return null;
  if (!hasStringProp(v, "gameName")) return null;
  return {
    slotId: v.slotId,
    slotOrder: v.slotOrder,
    gameId: v.gameId,
    gameName: v.gameName,
    playerYaml: optionalString(v, "playerYaml"),
  };
}

function parseSlotYamlData(payload: unknown, targetSlotId: string): SlotYamlData | null {
  const d = dataOf(payload);
  if (typeof d !== "object" || d === null) return null;
  if (!hasStringProp(d, "eventTitle")) return null;
  const rawSlots: unknown = Reflect.get(d, "slots");
  const rawGames: unknown = Reflect.get(d, "availableGames");
  if (!Array.isArray(rawSlots)) return null;
  if (!Array.isArray(rawGames)) return null;

  const slots = rawSlots.flatMap((s: unknown) => {
    const parsed = parseSlotYamlSlot(s);
    return parsed ? [parsed] : [];
  });

  const slot = slots.find((s) => s.slotId === targetSlotId) ?? null;
  if (!slot) return null;

  const rawGame: unknown = rawGames.find((g: unknown) => {
    if (typeof g !== "object" || g === null) return false;
    return hasStringProp(g, "id") && g.id === slot.gameId;
  });
  if (typeof rawGame !== "object" || rawGame === null) return null;
  if (!hasStringProp(rawGame, "id")) return null;

  const game: SlotYamlGame = {
    id: rawGame.id,
    isApworldReady: Reflect.get(rawGame, "isApworldReady") === true,
    defaultYaml: optionalString(rawGame, "defaultYaml"),
    optionTypes: asOptionTypesMap(Reflect.get(rawGame, "optionTypes")),
  };

  // Compute slot label - need sibling count for same game
  const sameGameCount = slots.filter((s) => s.gameId === slot.gameId).length;
  const slotLabel = sameGameCount > 1 ? `${slot.gameName} (monde ${slot.slotOrder})` : slot.gameName;

  return {
    eventTitle: d.eventTitle,
    registrationOpen: hasBooleanProp(d, "registrationOpen") ? d.registrationOpen : true,
    slot,
    game,
    slotLabel,
  };
}

export async function fetchSlotYamlData(
  registrationId: string,
  slotId: string,
): Promise<SlotYamlResult> {
  const raw = await fetchGameSelectionPayload(
    registrationId,
    "Impossible de charger les options de ce slot.",
  );
  if (raw.kind !== "success") return raw;
  // A payload that parses but does not contain this slot (or its game) is "not_found",
  // exactly like the pre-TanStack gate.
  const data = parseSlotYamlData(raw.payload, slotId);
  if (!data) return { kind: "not_found" };
  return { kind: "success", data };
}

// ── Session connection ────────────────────────────────────────────────────────

export type SessionPayload = {
  id: string;
  status: string;
  host: string | null;
  port: number | null;
  password: string | null;
};

export type SessionSlotInfo = {
  slotName: string;
  slotOrder: number;
  gameId: string;
  gameName: string;
};

export type ConnectionData = {
  session: SessionPayload | null;
  slots: SessionSlotInfo[];
};

export type SessionConnectionResult =
  | { kind: "success"; data: ConnectionData }
  | { kind: "not_found" }
  | { kind: "error"; message: string };

/**
 * Exported because the session gate also applies Mercure `/sessions/{id}` frames through it
 * (the SSE frame carries the same session shape as the REST payload).
 */
export function parseSession(x: unknown): SessionPayload | null {
  if (!x || typeof x !== "object") return null;
  if (!("id" in x) || typeof x.id !== "string") return null;
  if (!("status" in x) || typeof x.status !== "string") return null;
  return {
    id: x.id,
    status: x.status,
    host: "host" in x && typeof x.host === "string" ? x.host : null,
    port: "port" in x && typeof x.port === "number" ? x.port : null,
    password: "password" in x && typeof x.password === "string" ? x.password : null,
  };
}

function parseSessionSlot(v: unknown): SessionSlotInfo | null {
  if (typeof v !== "object" || v === null) return null;
  if (!hasStringProp(v, "slotName")) return null;
  if (!hasNumberProp(v, "slotOrder")) return null;
  if (!hasStringProp(v, "gameId")) return null;
  if (!hasStringProp(v, "gameName")) return null;
  return { slotName: v.slotName, slotOrder: v.slotOrder, gameId: v.gameId, gameName: v.gameName };
}

function parseConnectionData(payload: unknown): ConnectionData | null {
  const d = dataOf(payload);
  if (typeof d !== "object" || d === null) return null;

  const rawSession: unknown = Reflect.get(d, "session");
  const session = rawSession != null ? parseSession(rawSession) : null;

  const rawSlots: unknown = Reflect.get(d, "slots");
  if (!Array.isArray(rawSlots)) return null;

  const slots = rawSlots.flatMap((s: unknown) => {
    const parsed = parseSessionSlot(s);
    return parsed ? [parsed] : [];
  });

  return { session, slots };
}

export async function fetchSessionConnection(
  registrationId: string,
): Promise<SessionConnectionResult> {
  try {
    const res = await apiFetch(
      `${env.apiBaseUrl}/registrations/${registrationId}/session-connection`,
    );
    if (res.status === 404) return { kind: "not_found" };
    if (!res.ok) {
      return { kind: "error", message: "Impossible de charger les informations de connexion." };
    }
    const payload: unknown = await res.json();
    const data = parseConnectionData(payload);
    if (!data) return { kind: "error", message: "Réponse API invalide." };
    return { kind: "success", data };
  } catch {
    return { kind: "error", message: "Impossible de contacter l'API." };
  }
}

// ── Patch files ───────────────────────────────────────────────────────────────

function extractPatchFiles(payload: unknown): string[] {
  if (typeof payload !== "object" || payload === null) return [];
  const data: unknown = Reflect.get(payload, "data");
  if (typeof data !== "object" || data === null) return [];
  const files: unknown = Reflect.get(data, "files");
  if (!Array.isArray(files)) return [];
  return files.filter((file): file is string => typeof file === "string");
}

/** Any failure (HTTP or network) yields an empty list, like the pre-TanStack effect. */
export async function fetchRegistrationPatches(registrationId: string): Promise<string[]> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/registrations/${registrationId}/patches`);
    if (!res.ok) return [];
    const payload: unknown = await res.json();
    return extractPatchFiles(payload);
  } catch {
    return [];
  }
}

// ── My-registration CTA ───────────────────────────────────────────────────────

export type MyRegistrationCta =
  | { kind: "guest" }
  | { kind: "registered"; registrationId: string }
  | { kind: "not_registered" };

/**
 * Guest-graceful probe for the event-page CTA: an explicit 401/403 on the profile is a
 * guest (never a redirect), any other failure - including network errors - degrades to the
 * "not_registered" CTA, exactly like the pre-TanStack effect.
 */
export async function fetchMyRegistrationCta(eventId: string): Promise<MyRegistrationCta> {
  try {
    const profileRes = await apiFetch(`${env.apiBaseUrl}/account/profile`);
    if (profileRes.status === 401 || profileRes.status === 403) return { kind: "guest" };

    const regRes = await apiFetch(`${env.apiBaseUrl}/events/${eventId}/my-registration`);
    if (!regRes.ok) return { kind: "not_registered" };

    const payload: unknown = await regRes.json();
    const data = dataOf(payload);
    if (typeof data === "object" && data !== null && hasStringProp(data, "registrationId")) {
      return { kind: "registered", registrationId: data.registrationId };
    }
    return { kind: "not_registered" };
  } catch {
    return { kind: "not_registered" };
  }
}

// ── Seat counter fallback poll ────────────────────────────────────────────────

export async function fetchEventConfirmedRegistrations(eventId: string): Promise<number | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/events/${eventId}`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    const data = dataOf(payload);
    if (typeof data !== "object" || data === null) return null;
    if (!hasNumberProp(data, "confirmedRegistrations")) return null;
    return data.confirmedRegistrations;
  } catch {
    return null;
  }
}
