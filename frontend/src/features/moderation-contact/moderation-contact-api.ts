import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

/** Story 39.2 : un message du membre à la modération. */
export type ModerationContactMessage = {
  id: string;
  body: string;
  createdAt: string;
};

/** La sanction d'un membre banni ou suspendu, lue avec le laissez-passer de sa connexion refusée. */
export type BlockedSanction = {
  status: "banned" | "suspended";
  reason: string | null;
  suspendedUntil: string | null;
};

export type BlockedModerationContact = BlockedSanction & { messages: ModerationContactMessage[] };

export type AccountModerationContact = {
  available: boolean;
  messages: ModerationContactMessage[];
};

export type SendResult = { ok: true } | { ok: false; message: string };

const SEND_FAILED = "Impossible d'envoyer le message pour le moment. Réessaie dans quelques instants.";

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

function parseMessages(value: unknown): ModerationContactMessage[] {
  if (!Array.isArray(value)) return [];
  return value.flatMap((item: unknown) =>
    isRecord(item) && typeof item.id === "string" && typeof item.body === "string" && typeof item.createdAt === "string"
      ? [{ id: item.id, body: item.body, createdAt: item.createdAt }]
      : [],
  );
}

async function data(response: Response): Promise<Record<string, unknown> | null> {
  if (!response.ok) return null;
  const payload: unknown = await response.json();
  return isRecord(payload) && isRecord(payload.data) ? payload.data : null;
}

async function sent(response: Response): Promise<SendResult> {
  if (response.ok) return { ok: true };
  try {
    const payload: unknown = await response.json();
    if (isRecord(payload) && isRecord(payload.error) && typeof payload.error.message === "string") {
      return { ok: false, message: payload.error.message };
    }
  } catch {
    // Pas de corps exploitable : message générique.
  }
  return { ok: false, message: SEND_FAILED };
}

// Le laissez-passer n'ouvre aucune session : fetch simple, sans le rafraîchissement ni la redirection
// d'apiFetch, qui n'ont pas de sens pour un compte bloqué.
function blocked(init?: RequestInit): Promise<Response> {
  return fetch(`${env.apiBaseUrl}/moderation-contact`, { credentials: "include", ...init });
}

export async function fetchBlockedModerationContact(): Promise<BlockedModerationContact | null> {
  const d = await data(await blocked());
  if (d === null || (d.status !== "banned" && d.status !== "suspended")) return null;
  return {
    status: d.status,
    reason: typeof d.reason === "string" ? d.reason : null,
    suspendedUntil: typeof d.suspendedUntil === "string" ? d.suspendedUntil : null,
    messages: parseMessages(d.messages),
  };
}

export async function sendBlockedModerationMessage(body: string): Promise<SendResult> {
  try {
    return await sent(
      await blocked({ method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ body }) }),
    );
  } catch {
    return { ok: false, message: SEND_FAILED };
  }
}

export async function fetchAccountModerationContact(): Promise<AccountModerationContact> {
  const d = await data(await apiFetch(`${env.apiBaseUrl}/account/moderation-contact`));
  return { available: d?.available === true, messages: parseMessages(d?.messages) };
}

export async function sendAccountModerationMessage(body: string): Promise<SendResult> {
  try {
    return await sent(
      await apiFetch(`${env.apiBaseUrl}/account/moderation-contact`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ body }),
      }),
    );
  } catch {
    return { ok: false, message: SEND_FAILED };
  }
}
