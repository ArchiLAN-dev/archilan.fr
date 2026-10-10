import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasStringProp } from "@/lib/type-guards";

/** Story 43.13: a private group of the member's friends. */
export type FriendGroup = { id: string; name: string; memberIds: string[] };

export const FRIEND_GROUPS_KEY = ["friend-groups"] as const;

/** The groups as they now stand after a write, or why it was refused. */
export type FriendGroupsResult = { ok: true; groups: FriendGroup[] } | { ok: false; message: string };

function isFriendGroup(v: unknown): v is FriendGroup {
  if (typeof v !== "object" || v === null || !hasStringProp(v, "id") || !hasStringProp(v, "name") || !("memberIds" in v)) return false;
  const ids = v.memberIds;
  return Array.isArray(ids) && ids.every((id) => typeof id === "string");
}

function groupsOf(json: unknown): FriendGroup[] | null {
  const data = typeof json === "object" && json !== null && "data" in json ? json.data : null;
  return Array.isArray(data) && data.every(isFriendGroup) ? data : null;
}

function errorMessageOf(json: unknown): string | null {
  if (typeof json !== "object" || json === null || !("error" in json)) return null;
  const error = json.error;
  return typeof error === "object" && error !== null && hasStringProp(error, "message") ? error.message : null;
}

/** The member's groups, by name. Null on failure. */
export async function fetchFriendGroups(): Promise<FriendGroup[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/friend-groups`);
    return res.ok ? groupsOf(await res.json()) : null;
  } catch {
    return null;
  }
}

async function write(path: string, method: string, body?: unknown): Promise<FriendGroupsResult> {
  const failed = { ok: false as const, message: "Modification non enregistrée, réessaie." };
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/friend-groups${path}`, {
      method,
      ...(body === undefined ? {} : { headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) }),
    });
    const json: unknown = await res.json();
    if (!res.ok) return { ok: false, message: errorMessageOf(json) ?? failed.message };
    const groups = groupsOf(json);
    return groups === null ? failed : { ok: true, groups };
  } catch {
    return failed;
  }
}

export function createFriendGroup(name: string): Promise<FriendGroupsResult> {
  return write("", "POST", { name });
}

export function renameFriendGroup(groupId: string, name: string): Promise<FriendGroupsResult> {
  return write(`/${encodeURIComponent(groupId)}`, "PATCH", { name });
}

export function deleteFriendGroup(groupId: string): Promise<FriendGroupsResult> {
  return write(`/${encodeURIComponent(groupId)}`, "DELETE");
}

export function addToFriendGroup(groupId: string, userId: string): Promise<FriendGroupsResult> {
  return write(`/${encodeURIComponent(groupId)}/members/${encodeURIComponent(userId)}`, "PUT");
}

export function removeFromFriendGroup(groupId: string, userId: string): Promise<FriendGroupsResult> {
  return write(`/${encodeURIComponent(groupId)}/members/${encodeURIComponent(userId)}`, "DELETE");
}
