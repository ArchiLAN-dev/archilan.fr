import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

/** Story 41.1: one line of the pelles ledger, as the member's history shows it. */
export type PelleMovement = {
  id: string;
  amount: number;
  kind: "gold" | "event";
  eventId: string | null;
  eventTitle: string | null;
  reason: string;
  label: string;
  createdAt: string;
};

export type EventPelleBalance = { eventId: string; eventTitle: string; balance: number };

export type Wallet = {
  gold: number;
  events: EventPelleBalance[];
  history: { items: PelleMovement[]; page: number; perPage: number; total: number };
};

export type PelleCirculation = {
  goldInCirculation: number;
  created: number;
  destroyed: number;
  weeks: { weekStart: string; created: number; destroyed: number }[];
  byReason: { reason: string; created: number; destroyed: number }[];
};

export type AdjustPellesInput = {
  direction: "credit" | "debit";
  amount: number;
  kind: "gold" | "event";
  eventId: string | null;
  reason: string;
};

export type AdjustPellesResult =
  | { kind: "ok"; balanceBefore: number; balanceAfter: number }
  | { kind: "error"; message: string };

/** The reasons a movement can carry, in the words of the history (later stories add theirs). */
export const PELLE_REASON_LABELS: Record<string, string> = {
  admin_credit: "Crédit de l'équipe",
  admin_debit: "Débit de l'équipe",
  event_distribution: "Distribution d'événement",
  event_conversion: "Conversion en or (fin d'événement)",
  event_expired: "Expiration (fin d'événement)",
  hint_purchase: "Achat d'un indice",
  hint_refund: "Remboursement d'un indice",
  bounty_escrow: "Prime posée",
  bounty_reward: "Prime gagnée",
  bounty_refund: "Prime rendue",
};

export function pelleReasonLabel(reason: string): string {
  return PELLE_REASON_LABELS[reason] ?? reason;
}

function isMovement(v: unknown): v is PelleMovement {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "id") &&
    hasNumberProp(v, "amount") &&
    hasStringProp(v, "kind") &&
    (v.kind === "gold" || v.kind === "event") &&
    hasNullableStringProp(v, "eventId") &&
    hasNullableStringProp(v, "eventTitle") &&
    hasStringProp(v, "reason") &&
    hasStringProp(v, "label") &&
    hasStringProp(v, "createdAt")
  );
}

function isEventBalance(v: unknown): v is EventPelleBalance {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "eventId") &&
    hasStringProp(v, "eventTitle") &&
    hasNumberProp(v, "balance")
  );
}

export function isWallet(v: unknown): v is Wallet {
  if (typeof v !== "object" || v === null || !hasNumberProp(v, "gold")) return false;
  if (!("events" in v) || !Array.isArray(v.events) || !v.events.every(isEventBalance)) return false;
  if (!("history" in v) || typeof v.history !== "object" || v.history === null) return false;
  const history = v.history;
  return (
    hasNumberProp(history, "page") &&
    hasNumberProp(history, "perPage") &&
    hasNumberProp(history, "total") &&
    "items" in history &&
    Array.isArray(history.items) &&
    history.items.every(isMovement)
  );
}

function isFlow(v: unknown): v is { created: number; destroyed: number } {
  return typeof v === "object" && v !== null && hasNumberProp(v, "created") && hasNumberProp(v, "destroyed");
}

export function isCirculation(v: unknown): v is PelleCirculation {
  return (
    isFlow(v) &&
    hasNumberProp(v, "goldInCirculation") &&
    "weeks" in v &&
    Array.isArray(v.weeks) &&
    v.weeks.every((w) => isFlow(w) && hasStringProp(w, "weekStart")) &&
    "byReason" in v &&
    Array.isArray(v.byReason) &&
    v.byReason.every((r) => isFlow(r) && hasStringProp(r, "reason"))
  );
}

async function fetchWallet(url: string): Promise<Wallet | null> {
  try {
    const res = await apiFetch(url);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return isWallet(payload) ? payload : null;
  } catch {
    return null;
  }
}

/** « Mon portefeuille »: null when the API is unreachable or answers something unexpected. */
export function fetchMyWallet(page = 1): Promise<Wallet | null> {
  return fetchWallet(`${env.apiBaseUrl}/me/wallet?page=${page}`);
}

/** A member's wallet, read by an admin before crediting or debiting it. */
export function fetchMemberWallet(userId: string): Promise<Wallet | null> {
  return fetchWallet(`${env.apiBaseUrl}/admin/users/${userId}/pelles`);
}

export async function fetchPelleCirculation(): Promise<PelleCirculation | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/pelles/circulation`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return isCirculation(payload) ? payload : null;
  } catch {
    return null;
  }
}

/** The server owns the rules (bounds, balance, self-adjustment); this relays its message. */
export async function adjustMemberPelles(userId: string, input: AdjustPellesInput): Promise<AdjustPellesResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/users/${userId}/pelles`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ ...input, reason: input.reason.trim() }),
    });
    const payload: unknown = await res.json().catch(() => null);

    if (res.status === 201 && typeof payload === "object" && payload !== null && hasNumberProp(payload, "balanceBefore") && hasNumberProp(payload, "balanceAfter")) {
      return { kind: "ok", balanceBefore: payload.balanceBefore, balanceAfter: payload.balanceAfter };
    }
    if (res.status === 403) return { kind: "error", message: "Tu ne peux pas créditer ou débiter ton propre portefeuille." };
    if (res.status === 404) return { kind: "error", message: "Membre ou événement introuvable." };
    if (
      typeof payload === "object" &&
      payload !== null &&
      "error" in payload &&
      typeof payload.error === "object" &&
      payload.error !== null &&
      hasStringProp(payload.error, "message")
    ) {
      return { kind: "error", message: payload.error.message };
    }
    return { kind: "error", message: "L'opération a échoué." };
  } catch {
    return { kind: "error", message: "Impossible de contacter l'API." };
  }
}
