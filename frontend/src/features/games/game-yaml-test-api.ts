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

/**
 * Story 56.1: the tested world's number of locations and its progression items. Story 56.5: how long it should take
 * this player (null without a known pace) and the target of the event or run it was read for.
 */
export type TestedWorldSize = {
  locations: number;
  ownProgression: number;
  hours?: number | null;
  targetHours?: number | null;
  overTarget?: boolean;
};

/** Story 56.5: the event or private run a test is read for, which gives the target. */
export type YamlTestContext = { eventId: string } | { runId: string };

function contextQuery(context: YamlTestContext | undefined): string {
  if (context === undefined) return "";
  return "eventId" in context ? `?eventId=${encodeURIComponent(context.eventId)}` : `?runId=${encodeURIComponent(context.runId)}`;
}

export type GameYamlTestResult =
  | { kind: "result"; status: "pending" | "running" | "passed" | "failed"; error: string | null; world: TestedWorldSize | null }
  | { kind: "gone" }
  | { kind: "error" };

/** Story 9.57: where a test stands. `gone` = unknown or expired (the orchestrator keeps them 30 minutes). */
export async function getGameYamlTest(gameId: string, ticket: string, context?: YamlTestContext): Promise<GameYamlTestResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/games/${encodeURIComponent(gameId)}/yaml-test/${encodeURIComponent(ticket)}${contextQuery(context)}`);
    if (res.status === 404) return { kind: "gone" };
    if (!res.ok) return { kind: "error" };
    const json: unknown = await res.json();
    const data: unknown = typeof json === "object" && json !== null && "data" in json ? json.data : null;
    if (typeof data !== "object" || data === null || !hasStringProp(data, "status") || !hasNullableStringProp(data, "error")) return { kind: "error" };
    const status = data.status;
    if (status !== "pending" && status !== "running" && status !== "passed" && status !== "failed") return { kind: "error" };
    return { kind: "result", status, error: data.error, world: testedWorldSize(data) };
  } catch {
    return { kind: "error" };
  }
}

/** The first measured world of a passed test (the player's; a meta-game's partner comes after), or null. */
function testedWorldSize(data: object): TestedWorldSize | null {
  const measures: unknown = "measures" in data ? data.measures : null;
  if (!Array.isArray(measures)) return null;
  const first: unknown = measures[0];
  if (typeof first !== "object" || first === null) return null;
  const locations: unknown = "locations" in first ? first.locations : null;
  const own: unknown = "ownProgression" in first ? first.ownProgression : null;
  if (typeof locations !== "number" || typeof own !== "number") return null;
  const estimate: unknown = "estimate" in data ? data.estimate : null;
  if (typeof estimate !== "object" || estimate === null) return { locations, ownProgression: own };
  const hours: unknown = "hours" in estimate ? estimate.hours : null;
  const target: unknown = "targetHours" in estimate ? estimate.targetHours : null;
  const over: unknown = "overTarget" in estimate ? estimate.overTarget : false;
  return {
    locations,
    ownProgression: own,
    hours: typeof hours === "number" ? hours : null,
    targetHours: typeof target === "number" ? target : null,
    overTarget: over === true,
  };
}
