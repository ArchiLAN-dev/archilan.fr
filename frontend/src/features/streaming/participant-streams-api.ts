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
