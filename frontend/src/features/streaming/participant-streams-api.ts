import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasStringProp } from "@/lib/type-guards";

export type ParticipantStreamKind = "event" | "run" | "weekly";

export type ParticipantStream = {
  userId: string;
  slug: string;
  displayName: string | null;
  twitchLogin: string;
  avatarUrl: string | null;
  live: boolean;
  viewerCount: number | null;
};

// Per-session-type path segment under the API base (which already includes /api/v1).
const KIND_PATHS: Record<ParticipantStreamKind, string> = {
  event: "events",
  run: "runs",
  weekly: "weekly-runs",
};

export async function fetchParticipantStreams(
  kind: ParticipantStreamKind,
  id: string,
): Promise<ParticipantStream[]> {
  try {
    const response = await apiFetch(`${env.apiBaseUrl}/${KIND_PATHS[kind]}/${id}/participant-streams`);
    if (!response.ok) {
      return [];
    }

    const payload: unknown = await response.json();
    if (!isParticipantStreamsPayload(payload)) {
      return [];
    }

    return payload.data;
  } catch {
    return [];
  }
}

/**
 * Story 47.8: what the régie screen shows. A Twitch live, or a screen the régie receives on the LAN media server,
 * read in WebRTC (WHEP) from the régie's machine only.
 */
export type BroadcastStream =
  | ({ kind: "twitch" } & ParticipantStream)
  | { kind: "local"; userId: string; slug: string; displayName: string | null; whepUrl: string };

/** One key per player on screen: a player shows once, from the LAN or from Twitch. */
export function broadcastStreamKey(stream: BroadcastStream): string {
  return stream.kind === "local" ? `local-${stream.userId}` : `twitch-${stream.twitchLogin}`;
}

export function isBroadcastStream(v: unknown): v is BroadcastStream {
  if (typeof v !== "object" || v === null || !("kind" in v)) return false;
  if (v.kind === "twitch") return isParticipantStream(v);
  return (
    v.kind === "local" &&
    hasStringProp(v, "userId") &&
    hasStringProp(v, "slug") &&
    "displayName" in v &&
    (v.displayName === null || typeof v.displayName === "string") &&
    hasStringProp(v, "whepUrl")
  );
}

/**
 * Story 47.2: the lives the ArchiLAN broadcast may show (47.1 opt-outs left out). null when the read failed, so the
 * OBS screen keeps its last list on air instead of going blank. Story 47.8: the LAN screens come first.
 */
export async function fetchBroadcastStreams(eventId: string): Promise<BroadcastStream[] | null> {
  try {
    const response = await apiFetch(`${env.apiBaseUrl}/events/${encodeURIComponent(eventId)}/broadcast-streams`);
    if (!response.ok) {
      return null;
    }

    const payload: unknown = await response.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload) || !Array.isArray(payload.data)) return null;
    const streams: unknown[] = payload.data;
    return streams.every(isBroadcastStream) ? streams : null;
  } catch {
    return null;
  }
}

function isParticipantStream(v: unknown): v is ParticipantStream {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "userId")) return false;
  if (!hasStringProp(v, "slug")) return false;
  if (!("displayName" in v) || (v.displayName !== null && typeof v.displayName !== "string")) return false;
  if (!hasStringProp(v, "twitchLogin")) return false;
  if (!("avatarUrl" in v) || (v.avatarUrl !== null && typeof v.avatarUrl !== "string")) return false;
  if (!hasBooleanProp(v, "live")) return false;
  if (!("viewerCount" in v) || (v.viewerCount !== null && typeof v.viewerCount !== "number")) return false;
  return true;
}

function isParticipantStreamsPayload(payload: unknown): payload is { data: ParticipantStream[] } {
  if (typeof payload !== "object" || payload === null) return false;
  if (!("data" in payload)) return false;
  return Array.isArray(payload.data) && payload.data.every(isParticipantStream);
}
