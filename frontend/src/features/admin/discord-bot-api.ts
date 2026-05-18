import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

export type DiscordBotStatus = {
  botOnline: boolean;
  guildName: string | null;
  memberCount: number | null;
  activeMemberCount: number;
  managedRoleIds: string[];
  inviteUrl: string | null;
};

export type DiscordBotUser = {
  id: string;
  email: string;
  displayName: string | null;
  roles: string[];
  discordId: string;
  discordUsername: string | null;
  discordRoleSyncedAt: string | null;
  discordSyncError: string | null;
};

export type DiscordBotUsersResponse = {
  data: DiscordBotUser[];
  meta: { page: number; limit: number; total: number };
};

export type DiscordResyncResult = { queued: number };

function isDiscordBotStatusPayload(payload: unknown): payload is { data: DiscordBotStatus } {
  if (typeof payload !== "object" || payload === null) return false;
  if (!("data" in payload) || typeof payload.data !== "object" || payload.data === null)
    return false;
  const data = payload.data;
  if (!hasBooleanProp(data, "botOnline")) return false;
  const guildName = Reflect.get(data, "guildName");
  if (guildName !== null && typeof guildName !== "string") return false;
  const memberCount = Reflect.get(data, "memberCount");
  if (memberCount !== null && typeof memberCount !== "number") return false;
  if (!hasNumberProp(data, "activeMemberCount")) return false;
  const managedRoleIds = Reflect.get(data, "managedRoleIds");
  if (!Array.isArray(managedRoleIds) || !managedRoleIds.every((id) => typeof id === "string"))
    return false;
  const inviteUrl = Reflect.get(data, "inviteUrl");
  return inviteUrl === null || typeof inviteUrl === "string";
}

function isDiscordBotUser(v: unknown): v is DiscordBotUser {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "id") || !hasStringProp(v, "email") || !hasStringProp(v, "discordId"))
    return false;
  const displayName = Reflect.get(v, "displayName");
  if (displayName !== null && typeof displayName !== "string") return false;
  const roles = Reflect.get(v, "roles");
  if (!Array.isArray(roles) || !roles.every((r) => typeof r === "string")) return false;
  const discordUsername = Reflect.get(v, "discordUsername");
  if (discordUsername !== null && typeof discordUsername !== "string") return false;
  const discordRoleSyncedAt = Reflect.get(v, "discordRoleSyncedAt");
  if (discordRoleSyncedAt !== null && typeof discordRoleSyncedAt !== "string") return false;
  const discordSyncError = Reflect.get(v, "discordSyncError");
  return discordSyncError === null || typeof discordSyncError === "string";
}

function isDiscordBotUsersResponse(payload: unknown): payload is DiscordBotUsersResponse {
  if (typeof payload !== "object" || payload === null) return false;
  if (!("data" in payload) || !Array.isArray(payload.data)) return false;
  if (!payload.data.every(isDiscordBotUser)) return false;
  if (!("meta" in payload) || typeof payload.meta !== "object" || payload.meta === null)
    return false;
  return (
    hasNumberProp(payload.meta, "page") &&
    hasNumberProp(payload.meta, "limit") &&
    hasNumberProp(payload.meta, "total")
  );
}

function isDiscordResyncPayload(payload: unknown): payload is { data: DiscordResyncResult } {
  if (typeof payload !== "object" || payload === null) return false;
  if (!("data" in payload) || typeof payload.data !== "object" || payload.data === null)
    return false;
  return hasNumberProp(payload.data, "queued");
}

export async function fetchDiscordBotStatus(init?: RequestInit): Promise<DiscordBotStatus | null> {
  try {
    const response = await apiFetch(`${env.apiBaseUrl}/admin/discord-bot/status`, init);
    if (!response.ok) return null;
    const payload: unknown = await response.json();
    if (!isDiscordBotStatusPayload(payload)) return null;
    return payload.data;
  } catch {
    return null;
  }
}

export async function fetchDiscordBotUsers(
  page = 1,
  limit = 50,
  init?: RequestInit,
): Promise<DiscordBotUsersResponse | null> {
  try {
    const params = new URLSearchParams({ page: String(page), limit: String(limit) });
    const response = await apiFetch(
      `${env.apiBaseUrl}/admin/discord-bot/users?${params.toString()}`,
      init,
    );
    if (!response.ok) return null;
    const payload: unknown = await response.json();
    if (!isDiscordBotUsersResponse(payload)) return null;
    return payload;
  } catch {
    return null;
  }
}

export async function postDiscordResync(): Promise<DiscordResyncResult | null> {
  try {
    const response = await apiFetch(`${env.apiBaseUrl}/admin/discord-bot/resync`, {
      method: "POST",
    });
    if (!response.ok) return null;
    const payload: unknown = await response.json();
    if (!isDiscordResyncPayload(payload)) return null;
    return payload.data;
  } catch {
    return null;
  }
}
