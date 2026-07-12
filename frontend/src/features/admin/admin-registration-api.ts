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
