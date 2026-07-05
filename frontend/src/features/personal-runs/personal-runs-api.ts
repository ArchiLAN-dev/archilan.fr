import { env } from "@/lib/env";
import { hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

export type RunInvitePreview = {
  title: string;
  ownerName: string | null;
  participantCount: number;
  status: string;
};

// Discriminated result (instead of the usual `T | null`) so the join page can keep rendering the 404
// case ("lien invalide ou partie annulée") differently from server errors and network failures.
export type RunInvitePreviewResult =
  | { kind: "preview"; data: RunInvitePreview }
  | { kind: "not_found" }
  | { kind: "error"; message: string };

function isRunInvitePreview(v: unknown): v is RunInvitePreview {
  if (typeof v !== "object" || v === null) return false;
  return (
    hasStringProp(v, "title") &&
    hasNullableStringProp(v, "ownerName") &&
    hasNumberProp(v, "participantCount") &&
    hasStringProp(v, "status")
  );
}

/**
 * Fetches the public preview of a personal-run invite. Tokenless endpoint (the invite link itself is
 * the secret): plain fetch, NO credentials and NOT apiFetch. Never throws - every outcome is encoded
 * in the result's `kind`, so the caller can distinguish an invalid/cancelled invite (404) from a
 * server error or a network failure.
 */
export async function fetchInvitePreview(inviteToken: string): Promise<RunInvitePreviewResult> {
  try {
    const res = await fetch(`${env.apiBaseUrl}/runs/invite/${inviteToken}/preview`);

    if (res.status === 404) {
      return { kind: "not_found" };
    }

    if (!res.ok) {
      return { kind: "error", message: "Impossible de charger les informations de la partie." };
    }

    const payload: unknown = await res.json();
    if (
      typeof payload !== "object" ||
      payload === null ||
      !("data" in payload) ||
      !isRunInvitePreview(payload.data)
    ) {
      return { kind: "error", message: "Impossible de charger les informations de la partie." };
    }
    return { kind: "preview", data: payload.data };
  } catch {
    return { kind: "error", message: "Erreur réseau." };
  }
}
