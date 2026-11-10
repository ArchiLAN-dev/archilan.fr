import { apiErrorMessage } from "@/lib/api-error";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasNumberProp } from "@/lib/type-guards";

// ── Types ─────────────────────────────────────────────────────────────────────

export type Participant = {
  userId: string;
  displayName: string | null;
  email: string;
};

export type SelectedGame = {
  gameId: string;
  gameName: string;
};

export type PaymentSummary = {
  status: string;
  amountCents: number;
  syncedAt: string;
  isStale: boolean;
};

export type AdminRegistration = {
  registrationId: string;
  status: "reserved" | "cancelled";
  usedPrivateAccess: boolean;
  /** Story 47.1: the member lets the ArchiLAN broadcast show their stream. */
  broadcastConsent: boolean;
  createdAt: string;
  submittedAt: string | null;
  participant: Participant;
  selectedGames: SelectedGame[];
  gameSelectionComplete: boolean;
  payment: PaymentSummary | null;
};

export type RegistrationStatusFilter = "all" | "reserved" | "cancelled";

// Discriminated result (instead of the usual `T | null`) so the dashboard keeps rendering the
// denied (401/403) and not-found (404) cases differently from server errors and network failures.
// Never throws to the caller - except a re-thrown AbortError so TanStack Query can observe its own
// cancellations (unmount / query-key change) instead of caching them as error results.
export type AdminRegistrationsResult =
  | { kind: "ready"; registrations: AdminRegistration[]; total: number }
  | { kind: "not_found" }
  | { kind: "denied"; message: string }
  | { kind: "error"; message: string };

// The list payload is trusted item-wise (single publisher: the admin registrations endpoint);
// the envelope (`data` array + numeric `meta.total`) is checked structurally.
function isRegistrationsPayload(v: unknown): v is { data: AdminRegistration[]; meta: { total: number } } {
  if (typeof v !== "object" || v === null) return false;
  if (!("data" in v) || !Array.isArray(v.data)) return false;
  if (!("meta" in v) || typeof v.meta !== "object" || v.meta === null) return false;
  return hasNumberProp(v.meta, "total");
}

export async function fetchAdminRegistrations(
  eventId: string,
  statusFilter: RegistrationStatusFilter,
  signal?: AbortSignal,
): Promise<AdminRegistrationsResult> {
  try {
    const url = new URL(`${env.apiBaseUrl}/admin/events/${eventId}/registrations`);
    if (statusFilter !== "all") {
      url.searchParams.set("status", statusFilter);
    }

    const response = await apiFetch(url.toString(), { signal });

    if (response.status === 401 || response.status === 403) {
      return { kind: "denied", message: "Accès réservé aux admins ArchiLAN." };
    }

    if (response.status === 404) {
      return { kind: "not_found" };
    }

    if (!response.ok) {
      return { kind: "error", message: "Impossible de charger les inscriptions." };
    }

    const payload: unknown = await response.json();

    if (!isRegistrationsPayload(payload)) {
      return { kind: "error", message: "Réponse API invalide." };
    }

    return { kind: "ready", registrations: payload.data, total: payload.meta.total };
  } catch (err) {
    if (err instanceof DOMException && err.name === "AbortError") throw err;
    return { kind: "error", message: "Impossible de contacter l'API." };
  }
}

// ── Admin edits a registration (story 5.9) ───────────────────────────────────

/** The game-selection payload served to an admin (same shape as the player's, always open). */
export function adminGameSelectionUrl(eventId: string, registrationId: string): string {
  return `${env.apiBaseUrl}/admin/events/${eventId}/registrations/${registrationId}/game-selection`;
}

/** Where the YAML editor saves a slot when an admin edits it. */
export function adminSlotYamlUrl(eventId: string, registrationId: string, slotId: string): string {
  return `${env.apiBaseUrl}/admin/events/${eventId}/registrations/${registrationId}/slots/${slotId}/yaml`;
}

/** Story 5.10: a member-side rule the admin is about to cross, shown before they confirm. */
export type AdminWarning = { code: string; message: string };

/**
 * The outcome of an admin action that may cross member-side rules (story 5.10): done, warnings to acknowledge before
 * replaying it with `acknowledgeWarnings`, or an error to show.
 */
export type AdminActionResult<T = null> =
  | { kind: "ok"; data: T }
  | { kind: "warnings"; warnings: AdminWarning[] }
  | { kind: "error"; message: string };

/** The warnings of a `409 warnings_to_acknowledge` envelope, or null for any other payload. */
export function warningsOf(payload: unknown): AdminWarning[] | null {
  if (typeof payload !== "object" || payload === null || !("error" in payload)) return null;
  const error: unknown = payload.error;
  if (typeof error !== "object" || error === null || !("code" in error) || error.code !== "warnings_to_acknowledge") return null;
  if (!("details" in error) || typeof error.details !== "object" || error.details === null) return null;
  const details: object = error.details;
  if (!("warnings" in details) || !Array.isArray(details.warnings)) return null;
  const warnings: AdminWarning[] = [];
  for (const item of details.warnings) {
    if (typeof item === "object" && item !== null && "code" in item && "message" in item
      && typeof item.code === "string" && typeof item.message === "string") {
      warnings.push({ code: item.code, message: item.message });
    }
  }
  return warnings.length > 0 ? warnings : null;
}

async function adminAction<T>(
  url: string,
  method: "POST" | "PATCH",
  body: Record<string, unknown>,
  fallback: string,
  parse: (payload: unknown) => T | null,
): Promise<AdminActionResult<T>> {
  try {
    const res = await apiFetch(url, {
      body: JSON.stringify(body),
      headers: { "Content-Type": "application/json" },
      method,
    });
    const payload: unknown = await res.json().catch(() => null);
    if (res.ok) {
      const data = parse(payload);
      return data === null ? { kind: "error", message: "Réponse API invalide." } : { kind: "ok", data };
    }
    const warnings = res.status === 409 ? warningsOf(payload) : null;
    if (warnings !== null) return { kind: "warnings", warnings };
    return { kind: "error", message: apiErrorMessage(payload) ?? fallback };
  } catch {
    return { kind: "error", message: "Impossible de contacter l'API." };
  }
}

const done = (): true => true;

function registrationsUrl(eventId: string): string {
  return `${env.apiBaseUrl}/admin/events/${eventId}/registrations`;
}

/** Replaces the registration's slots by these games, in order; kept games keep their YAML (stories 5.4, 5.10). */
export function saveAdminRegistrationGames(
  eventId: string,
  registrationId: string,
  gameIds: string[],
  acknowledgeWarnings = false,
): Promise<AdminActionResult<true>> {
  return adminAction(
    `${registrationsUrl(eventId)}/${registrationId}`,
    "PATCH",
    { slots: gameIds.map((gameId) => ({ gameId })), acknowledgeWarnings },
    "Impossible d'enregistrer les jeux.",
    done,
  );
}

export type AdminRegistrationCreated = {
  registrationId: string;
  outcome: "created" | "restored" | "already_registered";
};

function isCreatedPayload(v: unknown): v is { data: AdminRegistrationCreated } {
  if (typeof v !== "object" || v === null || !("data" in v)) return false;
  const data: unknown = v.data;
  return typeof data === "object" && data !== null
    && "registrationId" in data && typeof data.registrationId === "string"
    && "outcome" in data && (data.outcome === "created" || data.outcome === "restored" || data.outcome === "already_registered");
}

/** Story 5.10: the admin registers a member on their behalf (a cancelled registration of theirs comes back). */
export function createAdminRegistration(
  eventId: string,
  userId: string,
  acknowledgeWarnings = false,
): Promise<AdminActionResult<AdminRegistrationCreated>> {
  return adminAction(
    registrationsUrl(eventId),
    "POST",
    { userId, acknowledgeWarnings },
    "Impossible d'inscrire ce joueur.",
    (payload) => (isCreatedPayload(payload) ? payload.data : null),
  );
}

/** Story 5.10: a cancelled registration comes back with its games and YAML. */
export function reactivateAdminRegistration(
  eventId: string,
  registrationId: string,
  acknowledgeWarnings = false,
): Promise<AdminActionResult<true>> {
  return adminAction(
    `${registrationsUrl(eventId)}/${registrationId}/reactivate`,
    "POST",
    { acknowledgeWarnings },
    "Impossible de réactiver l'inscription.",
    done,
  );
}

/** Story 5.10: the admin confirms on the member's behalf; the confirmation e-mail goes unless `sendEmail` is false. */
export function confirmAdminRegistration(
  eventId: string,
  registrationId: string,
  sendEmail: boolean,
  acknowledgeWarnings = false,
): Promise<AdminActionResult<true>> {
  return adminAction(
    `${registrationsUrl(eventId)}/${registrationId}/confirm`,
    "POST",
    { sendEmail, acknowledgeWarnings },
    "Impossible de confirmer l'inscription.",
    done,
  );
}
