import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

export type AdminUser = {
  id: string;
  email: string;
  displayName: string | null;
  role: "admin" | "member" | "user";
  roles: string[];
  status: "active" | "deleted";
  createdAt: string;
  updatedAt: string;
  deletedAt: string | null;
};

export type AdminUserFieldErrors = Partial<Record<"email" | "password" | "displayName", string>>;

// Discriminated result: 401/403 keep the directory's dedicated "denied" screen, other failures
// keep their distinct French messages. Never throws (AC-API2) - the old effect was one-shot too.
export type AdminUsersResult =
  | { kind: "ready"; users: AdminUser[] }
  | { kind: "denied"; message: string }
  | { kind: "error"; message: string };

export type CreateAdminUserResult =
  | { ok: true; user: AdminUser | null }
  | { ok: false; reason: "validation"; fieldErrors: AdminUserFieldErrors }
  | { ok: false; reason: "network" };

export async function fetchAdminUsers(
  query: string,
  role: string,
  signal?: AbortSignal,
): Promise<AdminUsersResult> {
  try {
    const params = new URLSearchParams();
    if (query !== "") params.set("q", query);
    if (role !== "all") params.set("role", role);
    const suffix = params.toString();

    const res = await apiFetch(`${env.apiBaseUrl}/admin/users${suffix === "" ? "" : `?${suffix}`}`, {
      signal,
    });

    if (res.status === 401 || res.status === 403) {
      return { kind: "denied", message: "Accès réservé aux admins ArchiLAN." };
    }
    if (!res.ok) {
      return { kind: "error", message: "Impossible de charger l'annuaire utilisateurs." };
    }

    const payload: unknown = await res.json();
    return { kind: "ready", users: isDirectoryPayload(payload) ? payload.data : [] };
  } catch {
    return { kind: "error", message: "Impossible de contacter l'API utilisateurs." };
  }
}

export async function updateAdminUserRole(
  userId: string,
  role: "user" | "member",
): Promise<AdminUser | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/users/${userId}/role`, {
      body: JSON.stringify({ role, confirmed: true }),
      headers: { "Content-Type": "application/json" },
      method: "PATCH",
    });
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return isUserPayload(payload) ? payload.data : null;
  } catch {
    return null;
  }
}

export async function createAdminUser(input: {
  email: string;
  password: string;
  displayName: string;
}): Promise<CreateAdminUserResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/users/admins`, {
      body: JSON.stringify(input),
      headers: { "Content-Type": "application/json" },
      method: "POST",
    });

    const payload: unknown = await res.json();

    if (!res.ok) {
      return { ok: false, reason: "validation", fieldErrors: fieldErrorsFromPayload(payload) };
    }

    return { ok: true, user: isUserPayload(payload) ? payload.data : null };
  } catch {
    return { ok: false, reason: "network" };
  }
}

// ─── Type guards & helpers ─────────────────────────────────────────────────────

function isDirectoryPayload(payload: unknown): payload is { data: AdminUser[] } {
  if (typeof payload !== "object" || payload === null || !("data" in payload)) return false;
  return Array.isArray(payload.data);
}

function isUserPayload(payload: unknown): payload is { data: AdminUser } {
  if (typeof payload !== "object" || payload === null || !("data" in payload)) return false;
  const data: unknown = payload.data;
  if (typeof data !== "object" || data === null) return false;
  return "id" in data && "role" in data;
}

function fieldErrorsFromPayload(payload: unknown): AdminUserFieldErrors {
  const fallback: AdminUserFieldErrors = { email: "Le formulaire contient une erreur." };

  if (typeof payload !== "object" || payload === null || !("error" in payload)) return fallback;
  const error: unknown = payload.error;
  if (typeof error !== "object" || error === null || !("details" in error)) return fallback;
  const details: unknown = error.details;
  if (typeof details !== "object" || details === null) return fallback;

  return {
    email: firstDetail(details, "email"),
    password: firstDetail(details, "password"),
    displayName: firstDetail(details, "displayName"),
  };
}

function firstDetail(details: object, key: keyof AdminUserFieldErrors): string | undefined {
  for (const [k, value] of Object.entries(details)) {
    if (k !== key) continue;
    return Array.isArray(value) && typeof value[0] === "string" ? value[0] : undefined;
  }
  return undefined;
}
