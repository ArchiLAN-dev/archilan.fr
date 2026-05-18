import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasStringProp } from "@/lib/type-guards";

export type AccountMembership = {
  status: "active" | "expired" | "none";
  expiresAt: string | null;
  startedAt: string | null;
};

export type AccountMembershipHistoryEntry = {
  id: string;
  status: string;
  startedAt: string | null;
  expiresAt: string | null;
  source: string;
};

export async function getAccountMembership(): Promise<AccountMembership | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/account/membership`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return isAccountMembershipPayload(payload) ? payload.data : null;
  } catch {
    return null;
  }
}

export async function getAccountMembershipHistory(): Promise<AccountMembershipHistoryEntry[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/account/memberships`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return isAccountMembershipHistoryPayload(payload) ? payload.data : null;
  } catch {
    return null;
  }
}

export async function getMembershipCheckoutUrl(): Promise<string | null> {
  try {
    const response = await apiFetch(`${env.apiBaseUrl}/membership/checkout`, {
      cache: "no-store",
    });

    if (!response.ok) {
      return null;
    }

    const payload: unknown = await response.json();

    if (!isMembershipCheckoutPayload(payload)) {
      return null;
    }

    return payload.data.checkoutEmbedUrl;
  } catch {
    return null;
  }
}

function isAccountMembershipPayload(v: unknown): v is { data: AccountMembership } {
  if (typeof v !== "object" || v === null) return false;
  if (!("data" in v) || typeof v.data !== "object" || v.data === null) return false;
  const d: unknown = v.data;
  if (typeof d !== "object" || d === null) return false;
  if (!("status" in d) || typeof d.status !== "string") return false;
  if (d.status !== "active" && d.status !== "expired" && d.status !== "none") return false;
  return true;
}

function isAccountMembershipHistoryEntry(v: unknown): v is AccountMembershipHistoryEntry {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "id") || !hasStringProp(v, "status") || !hasStringProp(v, "source")) return false;
  const startedAt = Reflect.get(v, "startedAt");
  if (startedAt !== null && typeof startedAt !== "string") return false;
  const expiresAt = Reflect.get(v, "expiresAt");
  return expiresAt === null || typeof expiresAt === "string";
}

function isAccountMembershipHistoryPayload(v: unknown): v is { data: AccountMembershipHistoryEntry[] } {
  if (typeof v !== "object" || v === null) return false;
  return "data" in v && Array.isArray(v.data) && v.data.every(isAccountMembershipHistoryEntry);
}

function isMembershipCheckoutPayload(
  payload: unknown,
): payload is { data: { checkoutEmbedUrl: string | null } } {
  if (typeof payload !== "object" || payload === null) return false;
  if (!("data" in payload) || typeof payload.data !== "object" || payload.data === null) return false;
  const data = payload.data;
  if (!("checkoutEmbedUrl" in data)) return false;
  const checkoutEmbedUrl = data.checkoutEmbedUrl;
  return checkoutEmbedUrl === null || typeof checkoutEmbedUrl === "string";
}
