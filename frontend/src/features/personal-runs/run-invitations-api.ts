import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasNullableStringProp, hasStringProp } from "@/lib/type-guards";
import type { FriendCard } from "@/features/community/community-friends-api";

/** Story 43.1: a friend invited by name into a personal run, as its owner follows it. */
export type RunInvitationStatus = "pending" | "accepted" | "declined" | "closed";

export type RunInvitation = {
  invitationId: string;
  status: RunInvitationStatus;
  invitedAt: string;
  respondedAt: string | null;
  invitee: FriendCard;
};

/** An invitation the member received, as « Mes parties » shows it. */
export type ReceivedRunInvitation = {
  invitationId: string;
  runId: string;
  runTitle: string;
  runStatus: string;
  invitedAt: string;
  inviter: FriendCard | null;
};

const STATUSES: RunInvitationStatus[] = ["pending", "accepted", "declined", "closed"];

function isCard(v: unknown): v is FriendCard {
  return typeof v === "object" && v !== null && hasStringProp(v, "userId") && hasStringProp(v, "slug") && hasNullableStringProp(v, "displayName") && hasNullableStringProp(v, "avatarUrl");
}

function isRunInvitation(v: unknown): v is RunInvitation {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "invitationId") || !hasStringProp(v, "invitedAt") || !hasNullableStringProp(v, "respondedAt")) return false;
  if (!("status" in v) || !STATUSES.some((s) => s === v.status)) return false;
  return "invitee" in v && isCard(v.invitee);
}

function isReceivedRunInvitation(v: unknown): v is ReceivedRunInvitation {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "invitationId") || !hasStringProp(v, "runId") || !hasStringProp(v, "runTitle")) return false;
  if (!hasStringProp(v, "runStatus") || !hasStringProp(v, "invitedAt")) return false;
  return "inviter" in v && (v.inviter === null || isCard(v.inviter));
}

function dataOf(json: unknown): unknown {
  return typeof json === "object" && json !== null && "data" in json ? json.data : null;
}

function errorMessageOf(json: unknown): string | null {
  if (typeof json !== "object" || json === null || !("error" in json)) return null;
  const error = json.error;
  return typeof error === "object" && error !== null && hasStringProp(error, "message") ? error.message : null;
}

/** The run's invitations, for its owner. Null on failure. */
export async function fetchRunInvitations(runId: string): Promise<RunInvitation[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/runs/${encodeURIComponent(runId)}/invitations`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return Array.isArray(data) && data.every(isRunInvitation) ? data : null;
  } catch {
    return null;
  }
}

export type SendInvitationsResult = { ok: true; invited: number; skipped: number } | { ok: false; message: string };

export async function sendRunInvitations(runId: string, userIds: string[]): Promise<SendInvitationsResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/runs/${encodeURIComponent(runId)}/invitations`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ userIds }),
    });
    const json: unknown = await res.json();
    if (!res.ok) {
      return { ok: false, message: errorMessageOf(json) ?? "Impossible d'envoyer les invitations." };
    }
    const data = dataOf(json);
    if (typeof data !== "object" || data === null || !("invited" in data) || !("skipped" in data) || !Array.isArray(data.invited) || !Array.isArray(data.skipped)) {
      return { ok: false, message: "Impossible d'envoyer les invitations." };
    }
    return { ok: true, invited: data.invited.length, skipped: data.skipped.length };
  } catch {
    return { ok: false, message: "Impossible d'envoyer les invitations." };
  }
}

/** The member's pending invitations. Null on failure. */
export async function fetchMyRunInvitations(): Promise<ReceivedRunInvitation[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/account/run-invitations`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return Array.isArray(data) && data.every(isReceivedRunInvitation) ? data : null;
  } catch {
    return null;
  }
}

export type AnswerInvitationResult = { ok: true; runId: string } | { ok: false; message: string };

export async function answerRunInvitation(invitationId: string, answer: "accept" | "decline"): Promise<AnswerInvitationResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/run-invitations/${encodeURIComponent(invitationId)}/${answer}`, { method: "POST" });
    const json: unknown = await res.json();
    const data = dataOf(json);
    if (res.ok && typeof data === "object" && data !== null && hasStringProp(data, "runId")) {
      return { ok: true, runId: data.runId };
    }
    return { ok: false, message: errorMessageOf(json) ?? "Impossible de répondre à l'invitation." };
  } catch {
    return { ok: false, message: "Impossible de répondre à l'invitation." };
  }
}
