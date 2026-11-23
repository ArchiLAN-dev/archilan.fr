import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

import type { YamlTestContext } from "./game-yaml-test-api";

/** Story 56.7: a size search, while it runs and once it is over. */
export type SizeSearchStatus = "running" | "found" | "not_found" | "failed";

export type SizeSearchProposal = { option: string; from: number; value: number; hours: number };

export type SizeSearchState = {
  id: string;
  status: SizeSearchStatus;
  targetHours: number;
  maxTries: number;
  /** A generation is under way. */
  running: boolean;
  tries: { option: string; value: number; hours: number | null }[];
  proposal: SizeSearchProposal | null;
  error: string | null;
};

const STATUSES: readonly string[] = ["running", "found", "not_found", "failed"];

function isStatus(value: unknown): value is SizeSearchStatus {
  return typeof value === "string" && STATUSES.includes(value);
}

function parseProposal(value: unknown): SizeSearchProposal | null {
  if (typeof value !== "object" || value === null) return null;
  if (!hasStringProp(value, "option") || !hasNumberProp(value, "from") || !hasNumberProp(value, "value") || !hasNumberProp(value, "hours")) return null;
  return { option: value.option, from: value.from, value: value.value, hours: value.hours };
}

export function parseSizeSearch(json: unknown): SizeSearchState | null {
  const data = typeof json === "object" && json !== null && "data" in json ? json.data : null;
  if (typeof data !== "object" || data === null || !hasStringProp(data, "id") || !("status" in data) || !isStatus(data.status)) return null;
  const tries = "tries" in data && Array.isArray(data.tries) ? data.tries : [];
  return {
    id: data.id,
    status: data.status,
    targetHours: hasNumberProp(data, "targetHours") ? data.targetHours : 0,
    maxTries: hasNumberProp(data, "maxTries") ? data.maxTries : 6,
    running: hasBooleanProp(data, "running") ? data.running : false,
    tries: tries.flatMap((t: unknown) =>
      typeof t === "object" && t !== null && hasStringProp(t, "option") && hasNumberProp(t, "value")
        ? [{ option: t.option, value: t.value, hours: hasNumberProp(t, "hours") ? t.hours : null }]
        : [],
    ),
    proposal: "proposal" in data ? parseProposal(data.proposal) : null,
    error: hasNullableStringProp(data, "error") ? data.error : null,
  };
}

/** The search id once started, the API's message otherwise. */
export async function startSizeSearch(
  gameId: string,
  yaml: string,
  context: YamlTestContext,
): Promise<{ ok: true; searchId: string } | { ok: false; message: string }> {
  const fallback = "La recherche n'a pas pu être lancée. Réessaie dans un instant.";
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/games/${encodeURIComponent(gameId)}/size-search`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ yaml, ...context }),
    });
    const body: unknown = await res.json().catch(() => null);
    if (res.ok) {
      const data = typeof body === "object" && body !== null && "data" in body ? body.data : null;
      return typeof data === "object" && data !== null && hasStringProp(data, "searchId") ? { ok: true, searchId: data.searchId } : { ok: false, message: fallback };
    }
    const error = typeof body === "object" && body !== null && "error" in body ? body.error : null;
    return { ok: false, message: typeof error === "object" && error !== null && hasStringProp(error, "message") ? error.message : fallback };
  } catch {
    return { ok: false, message: fallback };
  }
}

/** null when the search is unknown, someone else's, or the API cannot be reached. */
export async function getSizeSearch(gameId: string, searchId: string): Promise<SizeSearchState | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/games/${encodeURIComponent(gameId)}/size-search/${encodeURIComponent(searchId)}`, { cache: "no-store" });
    if (!res.ok) return null;
    return parseSizeSearch(await res.json());
  } catch {
    return null;
  }
}
