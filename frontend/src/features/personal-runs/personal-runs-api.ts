import { apiFetch } from "@/lib/apiFetch";
import type { OptionTypesMap } from "@/lib/archipelago-yaml";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";
import type { ParticipantGameSlot, ParticipantIdentity, PersonalRun } from "./types";

export type RunInvitePreview = {
  title: string;
  ownerName: string | null;
  participantCount: number;
  status: string;
};

// Discriminated result (instead of the usual `T | null`) so the join page can keep rendering the 404
// case ("lien invalide ou partie annulée") differently from server errors and network failures.
export type RunInvitePreviewResult =
  | { kind: "preview"; data: RunInvitePreview }
  | { kind: "not_found" }
  | { kind: "error"; message: string };

function isRunInvitePreview(v: unknown): v is RunInvitePreview {
  if (typeof v !== "object" || v === null) return false;
  return (
    hasStringProp(v, "title") &&
    hasNullableStringProp(v, "ownerName") &&
    hasNumberProp(v, "participantCount") &&
    hasStringProp(v, "status")
  );
}

/**
 * Fetches the public preview of a personal-run invite. Tokenless endpoint (the invite link itself is
 * the secret): plain fetch, NO credentials and NOT apiFetch. Never throws - every outcome is encoded
 * in the result's `kind`, so the caller can distinguish an invalid/cancelled invite (404) from a
 * server error or a network failure.
 */
export async function fetchInvitePreview(inviteToken: string): Promise<RunInvitePreviewResult> {
  try {
    const res = await fetch(`${env.apiBaseUrl}/runs/invite/${inviteToken}/preview`);

    if (res.status === 404) {
      return { kind: "not_found" };
    }

    if (!res.ok) {
      return { kind: "error", message: "Impossible de charger les informations de la partie." };
    }

    const payload: unknown = await res.json();
    if (
      typeof payload !== "object" ||
      payload === null ||
      !("data" in payload) ||
      !isRunInvitePreview(payload.data)
    ) {
      return { kind: "error", message: "Impossible de charger les informations de la partie." };
    }
    return { kind: "preview", data: payload.data };
  } catch {
    return { kind: "error", message: "Erreur réseau." };
  }
}

// ─── My runs (/runs/mine) ─────────────────────────────────────────────────────

export type MyRunsData = { owned: PersonalRun[]; joined: PersonalRun[] };

function isMyRunsData(v: unknown): v is MyRunsData {
  if (typeof v !== "object" || v === null) return false;
  return "owned" in v && Array.isArray(v.owned) && "joined" in v && Array.isArray(v.joined);
}

/** Fetches the current user's owned + joined personal runs. Never throws - returns null on any failure. */
export async function fetchMyRuns(): Promise<MyRunsData | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/runs/mine`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    if (
      typeof payload !== "object" ||
      payload === null ||
      !("data" in payload) ||
      !isMyRunsData(payload.data)
    ) {
      return null;
    }
    return payload.data;
  } catch {
    return null;
  }
}

// ─── Run detail (/runs/{runId}) ───────────────────────────────────────────────

// Discriminated result so the detail page keeps rendering 404/403 ("partie introuvable") differently
// from server errors and network failures. Never throws (AC-API2).
export type PersonalRunResult =
  | { kind: "ready"; run: PersonalRun }
  | { kind: "not_found" }
  | { kind: "error"; message: string };

function isPersonalRun(v: unknown): v is PersonalRun {
  if (typeof v !== "object" || v === null) return false;
  return (
    hasStringProp(v, "id") &&
    hasStringProp(v, "ownerId") &&
    hasStringProp(v, "title") &&
    hasStringProp(v, "status") &&
    hasBooleanProp(v, "isOwner") &&
    "participants" in v &&
    Array.isArray(v.participants)
  );
}

export async function fetchPersonalRun(runId: string): Promise<PersonalRunResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/runs/${runId}`);
    if (res.status === 404 || res.status === 403) {
      return { kind: "not_found" };
    }
    if (!res.ok) {
      return { kind: "error", message: "Une erreur est survenue lors du chargement." };
    }
    const payload: unknown = await res.json();
    if (
      typeof payload !== "object" ||
      payload === null ||
      !("data" in payload) ||
      !isPersonalRun(payload.data)
    ) {
      return { kind: "error", message: "Une erreur est survenue lors du chargement." };
    }
    return { kind: "ready", run: payload.data };
  } catch {
    return { kind: "error", message: "Impossible de joindre le serveur." };
  }
}

// ─── My game selection (/runs/{runId}/participants/me/game-selection) ────────

export type GameSelectionSlot = {
  slotId: string;
  slotOrder: number;
  gameId: string;
  gameName: string;
  playerYaml: string | null;
  apworldHash: string | null;
};

export type GameSelectionGame = {
  id: string;
  name: string;
  slug: string;
  description: string;
  availability: string;
  isApworldReady: boolean;
  defaultYaml: string | null;
  optionTypes: OptionTypesMap | null;
  coverImageUrl: string | null;
  coverImageAlt: string;
  platforms: string[];
  steamAppId: number | null;
};

export type RecentlyPlayedGame = {
  gameId: string;
  lastPlayedAt: string;
  runTitle: string;
};

export type GameSelectionData = {
  status: string;
  slots: GameSelectionSlot[];
  availableGames: GameSelectionGame[];
  recentlyPlayedGames: RecentlyPlayedGame[];
};

// Discriminated result: 401/403 keep triggering the pages' full-page login redirect ("unauthorized"),
// 404 keeps its dedicated screen, and HTTP vs network failures keep their distinct French messages
// (each consuming page maps `reason` onto its own wording). Never throws (AC-API2).
export type GameSelectionResult =
  | { kind: "data"; data: GameSelectionData }
  | { kind: "unauthorized" }
  | { kind: "not_found" }
  | { kind: "error"; reason: "http" | "network" };

function isGameSelectionPayload(
  v: unknown,
): v is { status?: string; slots: GameSelectionSlot[]; availableGames: GameSelectionGame[]; recentlyPlayedGames?: RecentlyPlayedGame[] } {
  if (typeof v !== "object" || v === null) return false;
  return "slots" in v && Array.isArray(v.slots) && "availableGames" in v && Array.isArray(v.availableGames);
}

export async function fetchMyGameSelection(runId: string): Promise<GameSelectionResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/runs/${runId}/participants/me/game-selection`);
    if (res.status === 401 || res.status === 403) {
      return { kind: "unauthorized" };
    }
    if (res.status === 404) {
      return { kind: "not_found" };
    }
    if (!res.ok) {
      return { kind: "error", reason: "http" };
    }
    const payload: unknown = await res.json();
    if (
      typeof payload !== "object" ||
      payload === null ||
      !("data" in payload) ||
      !isGameSelectionPayload(payload.data)
    ) {
      return { kind: "error", reason: "http" };
    }
    return {
      kind: "data",
      data: {
        status: payload.data.status ?? "draft",
        slots: payload.data.slots,
        availableGames: payload.data.availableGames,
        recentlyPlayedGames: payload.data.recentlyPlayedGames ?? [],
      },
    };
  } catch {
    return { kind: "error", reason: "network" };
  }
}

// ─── Participant detail (/runs/{runId}/participants/{participantId}/game-selection) ──

// Tri-state access encoding preserved from the effect era: 401 -> "unauthorized" (login redirect),
// 403 -> "forbidden" (dedicated screen), 404 -> "not_found". Never throws (AC-API2).
export type ParticipantGameSelectionResult =
  | { kind: "ready"; participant: ParticipantIdentity; slots: ParticipantGameSlot[] }
  | { kind: "unauthorized" }
  | { kind: "forbidden" }
  | { kind: "not_found" }
  | { kind: "error"; message: string };

function isParticipantGameSelectionData(
  v: unknown,
): v is { participant: ParticipantIdentity; slots: ParticipantGameSlot[] } {
  if (typeof v !== "object" || v === null) return false;
  return (
    "participant" in v &&
    typeof v.participant === "object" &&
    v.participant !== null &&
    "slots" in v &&
    Array.isArray(v.slots)
  );
}

export async function fetchParticipantGameSelection(
  runId: string,
  participantId: string,
): Promise<ParticipantGameSelectionResult> {
  try {
    const res = await apiFetch(
      `${env.apiBaseUrl}/runs/${runId}/participants/${participantId}/game-selection`,
    );
    if (res.status === 401) {
      return { kind: "unauthorized" };
    }
    if (res.status === 403) {
      return { kind: "forbidden" };
    }
    if (res.status === 404) {
      return { kind: "not_found" };
    }
    if (!res.ok) {
      return { kind: "error", message: "Impossible de charger la configuration du joueur." };
    }
    const payload: unknown = await res.json();
    if (
      typeof payload !== "object" ||
      payload === null ||
      !("data" in payload) ||
      !isParticipantGameSelectionData(payload.data)
    ) {
      return { kind: "error", message: "Impossible de charger la configuration du joueur." };
    }
    return { kind: "ready", participant: payload.data.participant, slots: payload.data.slots };
  } catch {
    return { kind: "error", message: "Impossible de contacter l'API." };
  }
}
