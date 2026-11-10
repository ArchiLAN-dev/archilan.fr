import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

/** Story 52.7: « Alice : 3 objets chez Bob » - and the half-days when Alice is there and Bob is not. */
export type DependencyPair = { waiting: string; holding: string; items: string[]; halfDays: string[] };

/** A generated session of the event, scored by its items at risk. */
export type DependencyGeneration = {
  sessionId: string;
  status: string;
  playthrough: "read" | "missing" | "skipped";
  score: number;
  pairs: DependencyPair[];
};

export type DependencyMap = { declared: boolean; sphereLimit: number; generations: DependencyGeneration[] };

function isObject(v: unknown): v is object {
  return typeof v === "object" && v !== null;
}

function isStringList(v: unknown): v is string[] {
  return Array.isArray(v) && v.every((item) => typeof item === "string");
}

export function isDependencyPair(v: unknown): v is DependencyPair {
  return isObject(v) && hasStringProp(v, "waiting") && hasStringProp(v, "holding") && "items" in v && isStringList(v.items) && "halfDays" in v && isStringList(v.halfDays);
}

export function isDependencyGeneration(v: unknown): v is DependencyGeneration {
  return (
    isObject(v) &&
    hasStringProp(v, "sessionId") &&
    hasStringProp(v, "status") &&
    "playthrough" in v &&
    (v.playthrough === "read" || v.playthrough === "missing" || v.playthrough === "skipped") &&
    hasNumberProp(v, "score") &&
    "pairs" in v &&
    Array.isArray(v.pairs) &&
    v.pairs.every(isDependencyPair)
  );
}

export function isDependencyMap(v: unknown): v is DependencyMap {
  return isObject(v) && hasBooleanProp(v, "declared") && hasNumberProp(v, "sphereLimit") && "generations" in v && Array.isArray(v.generations) && v.generations.every(isDependencyGeneration);
}

export async function fetchDependencyMap(eventId: string, spheres: number): Promise<DependencyMap | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/events/${eventId}/dependency-map?spheres=${spheres}`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return isObject(payload) && "data" in payload && isDependencyMap(payload.data) ? payload.data : null;
  } catch {
    return null;
  }
}

/** The generation to keep: the lowest score among those read; null when none was. */
export function bestGeneration(generations: DependencyGeneration[]): string | null {
  const read = generations.filter((generation) => generation.playthrough === "read");
  if (read.length < 2) return null;
  const best = read.reduce((min, generation) => (generation.score < min.score ? generation : min));
  return read.filter((generation) => generation.score === best.score).length === 1 ? best.sessionId : null;
}
