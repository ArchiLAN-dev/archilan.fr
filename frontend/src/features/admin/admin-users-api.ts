import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

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

/** Story 36.1: an existing account can now be promoted to (and demoted from) admin. */
export type AssignableRole = "user" | "member" | "admin";

/** The identity panel of a user's admin sheet (story 36.1). */
export type AdminUserDetail = {
  id: string;
  email: string;
  displayName: string | null;
  slug: string | null;
  role: AssignableRole;
  roles: string[];
  status: string;
  emailVerified: boolean;
  createdAt: string;
  updatedAt: string;
  deletedAt: string | null;
};

export type AdminUserDetailResult =
  | { kind: "ready"; user: AdminUserDetail }
  | { kind: "denied"; message: string }
  | { kind: "notFound" }
  | { kind: "error"; message: string };

/** One line of the account's audit timeline (story 36.5). */
export type AdminUserActivityEntry = {
  type: string;
  occurredAt: string;
  counterpartId: string | null;
  counterpartName: string | null;
  previousRole: string | null;
  newRole: string | null;
  subject: string | null;
  subjectId: string | null;
  granted: boolean | null;
};

function isActivityEntry(v: unknown): v is AdminUserActivityEntry {
  if (typeof v !== "object" || v === null) return false;
  const granted = Reflect.get(v, "granted");
  return (
    hasStringProp(v, "type") &&
    hasStringProp(v, "occurredAt") &&
    hasNullableStringProp(v, "counterpartId") &&
    hasNullableStringProp(v, "counterpartName") &&
    hasNullableStringProp(v, "previousRole") &&
    hasNullableStringProp(v, "newRole") &&
    hasNullableStringProp(v, "subject") &&
    hasNullableStringProp(v, "subjectId") &&
    (granted === null || typeof granted === "boolean")
  );
}

export async function fetchAdminUserActivity(userId: string): Promise<AdminUserActivityEntry[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/users/${userId}/activity`);
    if (!res.ok) return null;

    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload)) return null;
    if (!Array.isArray(payload.data) || !payload.data.every(isActivityEntry)) return null;

    return payload.data;
  } catch {
    return null;
  }
}

/** One membership row as the admin list serves it, admin-only fields included (story 36.3). */
export type AdminUserMembership = {
  id: string;
  status: string;
  startedAt: string;
  expiresAt: string;
  source: string;
  helloassoOrderId: string | null;
  adminNote: string | null;
};

export type AdminUserRegistration = {
  registrationId: string;
  eventSlug: string;
  eventTitle: string;
  eventStartDate: string | null;
  registrationStatus: string;
  slotCount: number;
  sessionStatus: string | null;
};

export type AdminUserParticipation = {
  memberships: AdminUserMembership[];
  registrations: AdminUserRegistration[];
};

function isMembership(v: unknown): v is AdminUserMembership {
  if (typeof v !== "object" || v === null) return false;
  return (
    hasStringProp(v, "id") &&
    hasStringProp(v, "status") &&
    hasStringProp(v, "startedAt") &&
    hasStringProp(v, "expiresAt") &&
    hasStringProp(v, "source") &&
    hasNullableStringProp(v, "helloassoOrderId") &&
    hasNullableStringProp(v, "adminNote")
  );
}

function isRegistration(v: unknown): v is AdminUserRegistration {
  if (typeof v !== "object" || v === null) return false;
  return (
    hasStringProp(v, "registrationId") &&
    hasStringProp(v, "eventSlug") &&
    hasStringProp(v, "eventTitle") &&
    hasNullableStringProp(v, "eventStartDate") &&
    hasStringProp(v, "registrationStatus") &&
    hasNumberProp(v, "slotCount") &&
    hasNullableStringProp(v, "sessionStatus")
  );
}

export async function fetchAdminUserParticipation(userId: string): Promise<AdminUserParticipation | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/users/${userId}/participation`);
    if (!res.ok) return null;

    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload)) return null;
    const data: unknown = payload.data;
    if (typeof data !== "object" || data === null) return null;
    if (!("memberships" in data) || !Array.isArray(data.memberships)) return null;
    if (!("registrations" in data) || !Array.isArray(data.registrations)) return null;
    if (!data.memberships.every(isMembership) || !data.registrations.every(isRegistration)) return null;

    return { memberships: data.memberships, registrations: data.registrations };
  } catch {
    return null;
  }
}

export async function fetchAdminUserDetail(userId: string): Promise<AdminUserDetailResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/users/${userId}`);

    if (res.status === 401 || res.status === 403) {
      return { kind: "denied", message: "Accès réservé aux admins ArchiLAN." };
    }
    if (res.status === 404) return { kind: "notFound" };
    if (!res.ok) return { kind: "error", message: "Impossible de charger cette fiche utilisateur." };

    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload)) {
      return { kind: "error", message: "Réponse inattendue de l'API utilisateurs." };
    }
    if (!isAdminUserDetail(payload.data)) {
      return { kind: "error", message: "Réponse inattendue de l'API utilisateurs." };
    }

    return { kind: "ready", user: payload.data };
  } catch {
    return { kind: "error", message: "Impossible de contacter l'API utilisateurs." };
  }
}

export async function updateAdminUserRole(
  userId: string,
  role: AssignableRole,
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

function isAdminUserDetail(v: unknown): v is AdminUserDetail {
  if (typeof v !== "object" || v === null) return false;
  const role = Reflect.get(v, "role");
  return (
    hasStringProp(v, "id") &&
    hasStringProp(v, "email") &&
    hasNullableStringProp(v, "displayName") &&
    hasNullableStringProp(v, "slug") &&
    (role === "user" || role === "member" || role === "admin") &&
    "roles" in v &&
    Array.isArray(Reflect.get(v, "roles")) &&
    hasBooleanProp(v, "emailVerified") &&
    hasStringProp(v, "status") &&
    hasStringProp(v, "createdAt") &&
    hasStringProp(v, "updatedAt") &&
    hasNullableStringProp(v, "deletedAt")
  );
}

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
