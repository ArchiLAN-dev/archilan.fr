import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasNullableStringProp, hasStringProp } from "@/lib/type-guards";

const START_ERROR = "Le test n'a pas pu être lancé. Réessaie dans un instant.";

function apiErrorMessage(json: unknown, fallback: string): string {
  const error: unknown = typeof json === "object" && json !== null && "error" in json ? json.error : null;
  return typeof error === "object" && error !== null && hasStringProp(error, "message") ? error.message : fallback;
}

/**
 * Story 9.57: a solo test generation of an editor's YAML against the game's current apworld. Nothing is saved.
 * The ticket reads the result back, for this player only.
 */
export async function startGameYamlTest(
  gameId: string,
  yaml: string,
): Promise<{ ok: true; ticket: string } | { ok: false; message: string }> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/games/${encodeURIComponent(gameId)}/yaml-test`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ yaml }),
    });
    const json: unknown = await res.json().catch(() => null);
    if (res.ok) {
      const data: unknown = typeof json === "object" && json !== null && "data" in json ? json.data : null;
      if (typeof data === "object" && data !== null && hasStringProp(data, "ticket")) return { ok: true, ticket: data.ticket };
      return { ok: false, message: START_ERROR };
    }
    return { ok: false, message: apiErrorMessage(json, START_ERROR) };
  } catch {
    return { ok: false, message: START_ERROR };
  }
}

export type GameYamlTestResult =
  | { kind: "result"; status: "pending" | "running" | "passed" | "failed"; error: string | null }
  | { kind: "gone" }
  | { kind: "error" };

/** Story 9.57: where a test stands. `gone` = unknown or expired (the orchestrator keeps them 30 minutes). */
export async function getGameYamlTest(gameId: string, ticket: string): Promise<GameYamlTestResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/games/${encodeURIComponent(gameId)}/yaml-test/${encodeURIComponent(ticket)}`);
    if (res.status === 404) return { kind: "gone" };
    if (!res.ok) return { kind: "error" };
    const json: unknown = await res.json();
    const data: unknown = typeof json === "object" && json !== null && "data" in json ? json.data : null;
    if (typeof data !== "object" || data === null || !hasStringProp(data, "status") || !hasNullableStringProp(data, "error")) return { kind: "error" };
    const status = data.status;
    if (status !== "pending" && status !== "running" && status !== "passed" && status !== "failed") return { kind: "error" };
    return { kind: "result", status, error: data.error };
  } catch {
    return { kind: "error" };
  }
}
