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

/** The moderation panel's consolidated read (story 36.2). */
export type AdminModerationAction = {
  id: string;
  action: string;
  reason: string;
  createdAt: string;
  actorId: string;
  actorName: string | null;
  relatedReportId: string | null;
};

export type AdminUserModeration = {
  state: { suspendedUntil: string | null; bannedAt: string | null; reason: string | null };
  unresolvedReportCount: number;
  severityScore: number;
  actions: AdminModerationAction[];
};

function isModerationAction(v: unknown): v is AdminModerationAction {
  if (typeof v !== "object" || v === null) return false;
  return (
    hasStringProp(v, "id") &&
    hasStringProp(v, "action") &&
    hasStringProp(v, "reason") &&
    hasStringProp(v, "createdAt") &&
    hasStringProp(v, "actorId") &&
    hasNullableStringProp(v, "actorName") &&
    hasNullableStringProp(v, "relatedReportId")
  );
}

export async function fetchAdminUserModeration(userId: string): Promise<AdminUserModeration | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/community/accounts/${userId}/moderation`);
    if (!res.ok) return null;

    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload)) return null;
    const data: unknown = payload.data;
    if (typeof data !== "object" || data === null) return null;
    if (!hasNumberProp(data, "unresolvedReportCount") || !hasNumberProp(data, "severityScore")) return null;
    if (!("actions" in data) || !Array.isArray(data.actions) || !data.actions.every(isModerationAction)) {
      return null;
    }
    if (!("state" in data)) return null;
    const state = data.state;
    if (typeof state !== "object" || state === null) return null;
    if (
      !hasNullableStringProp(state, "suspendedUntil") ||
      !hasNullableStringProp(state, "bannedAt") ||
      !hasNullableStringProp(state, "reason")
    ) {
      return null;
    }

    return {
      state: { suspendedUntil: state.suspendedUntil, bannedAt: state.bannedAt, reason: state.reason },
      unresolvedReportCount: data.unresolvedReportCount,
      severityScore: data.severityScore,
      actions: data.actions,
    };
  } catch {
    return null;
  }
}

export type ModerationCommand = "warn" | "suspend" | "ban" | "lift";

/** Returns null on success, or a message to show. The server owns the rules; this only relays them. */
export async function applyModerationAction(
  userId: string,
  command: ModerationCommand,
  reason: string,
  until?: string,
): Promise<string | null> {
  try {
    const body: Record<string, string> = { reason };
    if (command === "suspend" && until !== undefined) body.until = until;

    const res = await apiFetch(`${env.apiBaseUrl}/admin/community/accounts/${userId}/${command}`, {
      body: JSON.stringify(body),
      headers: { "Content-Type": "application/json" },
      method: "POST",
    });

    if (res.status === 204) return null;
    if (res.status === 403) return "Ce compte ne peut pas être modéré (administrateur, ou toi-même).";
    if (res.status === 422) return "Action refusée : motif requis, et une suspension doit finir dans le futur.";
    return "L'action de modération a échoué.";
  } catch {
    return "Impossible de contacter l'API de modération.";
  }
}

/** The gaming panel's read (story 36.4). */
export type AdminUserRun = { id: string; title: string; status: string };

export type AdminUserHistoryEntry = {
  sessionId: string | null;
  context: string | null;
  game: string | null;
  finishedAt: string | null;
};

export type AdminUserGaming = {
  progress: { level: number; xp: number; runsParticipated: number; goalCompletions: number; totalChecksDone: number; achievementsUnlocked: number };
  accounts: { discordId: string | null; discordUsername: string | null; steamProfile: string | null };
  ownedRuns: AdminUserRun[];
  joinedRuns: AdminUserRun[];
  history: AdminUserHistoryEntry[];
};

function isRun(v: unknown): v is AdminUserRun {
  if (typeof v !== "object" || v === null) return false;
  return hasStringProp(v, "id") && hasStringProp(v, "title") && hasStringProp(v, "status");
}

function isHistoryEntry(v: unknown): v is AdminUserHistoryEntry {
  if (typeof v !== "object" || v === null) return false;
  return (
    hasNullableStringProp(v, "sessionId") &&
    hasNullableStringProp(v, "context") &&
    hasNullableStringProp(v, "game") &&
    hasNullableStringProp(v, "finishedAt")
  );
}

export async function fetchAdminUserGaming(userId: string): Promise<AdminUserGaming | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/users/${userId}/gaming`);
    if (!res.ok) return null;

    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload)) return null;
    const data = payload.data;
    if (typeof data !== "object" || data === null) return null;

    if (!("progress" in data) || !("accounts" in data)) return null;
    const progress = data.progress;
    const accounts = data.accounts;
    if (typeof progress !== "object" || progress === null) return null;
    if (typeof accounts !== "object" || accounts === null) return null;
    if (!hasNumberProp(progress, "level") || !hasNumberProp(progress, "xp")) return null;
    if (
      !hasNullableStringProp(accounts, "discordId") ||
      !hasNullableStringProp(accounts, "discordUsername") ||
      !hasNullableStringProp(accounts, "steamProfile")
    ) {
      return null;
    }

    for (const key of ["ownedRuns", "joinedRuns", "history"] as const) {
      if (!(key in data) || !Array.isArray(Reflect.get(data, key))) return null;
    }
    const owned: unknown[] = Reflect.get(data, "ownedRuns");
    const joined: unknown[] = Reflect.get(data, "joinedRuns");
    const history: unknown[] = Reflect.get(data, "history");
    if (!owned.every(isRun) || !joined.every(isRun) || !history.every(isHistoryEntry)) return null;

    return {
      progress: {
        level: progress.level,
        xp: progress.xp,
        runsParticipated: hasNumberProp(progress, "runsParticipated") ? progress.runsParticipated : 0,
        goalCompletions: hasNumberProp(progress, "goalCompletions") ? progress.goalCompletions : 0,
        totalChecksDone: hasNumberProp(progress, "totalChecksDone") ? progress.totalChecksDone : 0,
        achievementsUnlocked: hasNumberProp(progress, "achievementsUnlocked") ? progress.achievementsUnlocked : 0,
      },
      accounts: {
        discordId: accounts.discordId,
        discordUsername: accounts.discordUsername,
        steamProfile: accounts.steamProfile,
      },
      ownedRuns: owned,
      joinedRuns: joined,
      history,
    };
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
