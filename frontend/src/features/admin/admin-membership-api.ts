import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

export type AdminMembershipEntry = {
  id: string;
  userId: string;
  email: string;
  displayName: string | null;
  status: "active" | "expired" | "cancelled";
  startedAt: string | null;
  expiresAt: string | null;
  source: string;
  helloassoOrderId: string | null;
  adminNote: string | null;
};

type ListPayload = {
  data: AdminMembershipEntry[];
  meta: { page: number; limit: number; total: number };
};

export type AdminMembershipFilters = {
  search?: string;
  status?: "active" | "expired" | "cancelled" | "";
  page?: number;
  userId?: string;
  dateFrom?: string;
  dateTo?: string;
};

export async function fetchAdminMemberships(
  filters: AdminMembershipFilters,
): Promise<ListPayload | null> {
  try {
    const params = new URLSearchParams({ page: String(filters.page ?? 1), limit: "50" });
    if (filters.search?.trim()) params.set("search", filters.search.trim());
    if (filters.status) params.set("status", filters.status);
    if (filters.userId) params.set("userId", filters.userId);
    if (filters.dateFrom) params.set("dateFrom", filters.dateFrom);
    if (filters.dateTo) params.set("dateTo", filters.dateTo);

    const res = await apiFetch(`${env.apiBaseUrl}/admin/memberships?${params.toString()}`);

    if (!res.ok) return null;

    const payload: unknown = await res.json();
    return isListPayload(payload) ? payload : null;
  } catch {
    return null;
  }
}

export type UserSearchResult = {
  id: string;
  email: string;
  displayName: string | null;
};

export async function searchUsersForMembership(query: string): Promise<UserSearchResult[]> {
  try {
    const params = new URLSearchParams({ q: query });
    const res = await apiFetch(`${env.apiBaseUrl}/admin/users?${params.toString()}`);
    if (!res.ok) return [];
    const payload: unknown = await res.json();
    return isUserSearchPayload(payload) ? payload.data : [];
  } catch {
    return [];
  }
}

export type AdminMembershipEditPayload = {
  startedAt: string;
  expiresAt?: string;
  adminNote?: string | null;
};

export async function updateAdminMembership(
  membershipId: string,
  payload: AdminMembershipEditPayload,
): Promise<AdminMembershipEntry | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/memberships/${membershipId}`, {
      method: "PATCH",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    if (!res.ok) return null;
    const data: unknown = await res.json();
    if (!isCreatePayload(data)) return null;
    return data.data;
  } catch {
    return null;
  }
}

export async function deleteAdminMembership(membershipId: string): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/memberships/${membershipId}`, {
      method: "DELETE",
    });
    return res.ok || res.status === 204;
  } catch {
    return false;
  }
}

export async function createAdminMembership(
  userId: string,
  adminNote?: string,
  startedAt?: string,
  expiresAt?: string,
): Promise<AdminMembershipEntry | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/memberships`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        userId,
        adminNote: adminNote || undefined,
        startedAt: startedAt || undefined,
        expiresAt: expiresAt || undefined,
      }),
    });

    if (!res.ok) return null;

    const payload: unknown = await res.json();
    if (!isCreatePayload(payload)) return null;

    return payload.data;
  } catch {
    return null;
  }
}

export async function fetchUserActiveMembership(userId: string): Promise<AdminMembershipEntry | null> {
  try {
    const params = new URLSearchParams({ status: "active", userId, page: "1", limit: "1" });
    const res = await apiFetch(`${env.apiBaseUrl}/admin/memberships?${params.toString()}`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    if (!isListPayload(payload) || payload.data.length === 0) return null;
    return payload.data[0] ?? null;
  } catch {
    return null;
  }
}

function isUserSearchPayload(v: unknown): v is { data: UserSearchResult[] } {
  if (typeof v !== "object" || v === null) return false;
  if (!("data" in v)) return false;
  return Array.isArray(v.data);
}

function isListPayload(v: unknown): v is ListPayload {
  if (typeof v !== "object" || v === null) return false;
  if (!("data" in v) || !Array.isArray(v.data)) return false;
  if (!("meta" in v) || typeof v.meta !== "object" || v.meta === null) return false;
  return "total" in v.meta && typeof v.meta.total === "number";
}

function isCreatePayload(v: unknown): v is { data: AdminMembershipEntry } {
  if (typeof v !== "object" || v === null) return false;
  if (!("data" in v) || typeof v.data !== "object" || v.data === null) return false;
  const d = v.data;
  return "id" in d && typeof d.id === "string" && "status" in d;
}
