import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

export type DashboardStats = {
  totalActiveRegistrations: number;
  gameCount: number;
  userCount: number;
  activeMemberCount: number;
  totalRevenueCents: number;
};

function isDashboardStats(v: unknown): v is DashboardStats {
  if (typeof v !== "object" || v === null) return false;
  if (!("totalActiveRegistrations" in v) || typeof v.totalActiveRegistrations !== "number") return false;
  if (!("gameCount" in v) || typeof v.gameCount !== "number") return false;
  if (!("userCount" in v) || typeof v.userCount !== "number") return false;
  if (!("activeMemberCount" in v) || typeof v.activeMemberCount !== "number") return false;
  return "totalRevenueCents" in v && typeof v.totalRevenueCents === "number";
}

// Silently null on any failure: the admin home renders "-" placeholders, exactly like the
// former fire-and-forget Promise.all effect. Never throws (AC-API2).
export async function fetchAdminDashboardStats(): Promise<DashboardStats | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/dashboard-stats`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload)) return null;
    return isDashboardStats(payload.data) ? payload.data : null;
  } catch {
    return null;
  }
}
