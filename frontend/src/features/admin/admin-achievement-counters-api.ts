import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

// Story 30.57: the admin counters of checks and items that achievements read as `counter:{id}`.

export type CounterKind = "location" | "item";
export type CounterMode = "total" | "max_session" | "distinct";

export const COUNTER_KIND_LABELS: Record<CounterKind, string> = { location: "Check fait", item: "Item reçu" };
export const COUNTER_MODE_LABELS: Record<CounterMode, string> = {
  total: "Total",
  max_session: "Le plus dans une même partie",
  distinct: "Noms différents atteints",
};

export type AchievementCounter = {
  id: string;
  label: string;
  gameId: string;
  gameName: string | null;
  kind: CounterKind;
  targets: string[];
  mode: CounterMode;
  withOthersOnly: boolean;
  usedBy: number;
};

export type CounterGame = { id: string; name: string; archipelagoName: string; introspected: boolean };

export type CounterPayload = {
  label: string;
  gameId: string;
  kind: CounterKind;
  targets: string[];
  mode: CounterMode;
  withOthersOnly: boolean;
};

export type CounterNames = {
  source: "introspection" | "feed";
  total: number;
  names: string[];
  groups: { name: string; items: string[] }[];
  /** The picked names the game's list no longer has (an apworld updated since). */
  unknown: string[];
};

export type CounterMutation = { ok: true; value: AchievementCounter } | { ok: false; error: string };

const BASE = () => `${env.apiBaseUrl}/admin/community/achievement-counters`;

export async function fetchAchievementCounters(): Promise<{ counters: AchievementCounter[]; games: CounterGame[] } | null> {
  try {
    const res = await apiFetch(BASE());
    if (!res.ok) return null;
    const json: unknown = await res.json();
    if (typeof json !== "object" || json === null || !("data" in json) || !Array.isArray(json.data)) return null;
    const meta: unknown = "meta" in json ? json.meta : null;
    const games: unknown = typeof meta === "object" && meta !== null && "games" in meta ? meta.games : null;
    return { counters: json.data.filter(isCounter), games: Array.isArray(games) ? games.filter(isCounterGame) : [] };
  } catch {
    return null;
  }
}

/** The names of one game matching what was typed, the picked ones left out; null when the game cannot carry a counter. */
export async function fetchCounterNames(gameId: string, kind: CounterKind, query: string, exclude: string[], signal?: AbortSignal): Promise<CounterNames | null> {
  const params = new URLSearchParams({ gameId, kind, q: query });
  for (const name of exclude) params.append("exclude[]", name);
  try {
    const res = await apiFetch(`${BASE()}/names?${params.toString()}`, { signal });
    if (!res.ok) return null;
    const json: unknown = await res.json();
    const data: unknown = typeof json === "object" && json !== null && "data" in json ? json.data : null;
    return isCounterNames(data) ? data : null;
  } catch {
    return null;
  }
}

export async function createAchievementCounter(payload: CounterPayload): Promise<CounterMutation> {
  return mutate(BASE(), "POST", payload);
}

export async function updateAchievementCounter(id: string, payload: CounterPayload): Promise<CounterMutation> {
  return mutate(`${BASE()}/${encodeURIComponent(id)}`, "PATCH", payload);
}

/** `in_use` when an achievement still reads it. */
export async function deleteAchievementCounter(id: string): Promise<"deleted" | "in_use" | "failed"> {
  try {
    const res = await apiFetch(`${BASE()}/${encodeURIComponent(id)}`, { method: "DELETE" });
    if (res.status === 204) return "deleted";
    return res.status === 409 ? "in_use" : "failed";
  } catch {
    return "failed";
  }
}

async function mutate(url: string, method: "POST" | "PATCH", payload: CounterPayload): Promise<CounterMutation> {
  try {
    const res = await apiFetch(url, { method, headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload) });
    const json: unknown = await res.json().catch(() => null);
    if (!res.ok) return { ok: false, error: errorMessage(json) };
    const data: unknown = typeof json === "object" && json !== null && "data" in json ? json.data : null;
    return isCounter(data) ? { ok: true, value: data } : { ok: false, error: "Réponse invalide du serveur." };
  } catch {
    return { ok: false, error: "Erreur réseau." };
  }
}

function errorMessage(v: unknown): string {
  if (typeof v === "object" && v !== null && "error" in v) {
    const err: unknown = v.error;
    if (typeof err === "object" && err !== null && "message" in err && typeof err.message === "string") return err.message;
  }
  return "L'enregistrement a échoué.";
}

function isStringList(v: unknown): v is string[] {
  return Array.isArray(v) && v.every((s) => typeof s === "string");
}

function isCounter(v: unknown): v is AchievementCounter {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "id") &&
    hasStringProp(v, "label") &&
    hasStringProp(v, "gameId") &&
    hasNullableStringProp(v, "gameName") &&
    hasStringProp(v, "kind") &&
    (v.kind === "location" || v.kind === "item") &&
    hasStringProp(v, "mode") &&
    (v.mode === "total" || v.mode === "max_session" || v.mode === "distinct") &&
    "targets" in v &&
    isStringList(v.targets) &&
    hasBooleanProp(v, "withOthersOnly") &&
    hasNumberProp(v, "usedBy")
  );
}

function isCounterGame(v: unknown): v is CounterGame {
  return typeof v === "object" && v !== null && hasStringProp(v, "id") && hasStringProp(v, "name") && hasStringProp(v, "archipelagoName") && hasBooleanProp(v, "introspected");
}

function isCounterNames(v: unknown): v is CounterNames {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "source") || (v.source !== "introspection" && v.source !== "feed")) return false;
  if (!hasNumberProp(v, "total") || !("names" in v) || !isStringList(v.names)) return false;
  if (!("unknown" in v) || !isStringList(v.unknown)) return false;
  return (
    "groups" in v &&
    Array.isArray(v.groups) &&
    v.groups.every((g: unknown) => typeof g === "object" && g !== null && hasStringProp(g, "name") && "items" in g && isStringList(g.items))
  );
}
