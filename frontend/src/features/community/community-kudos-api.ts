import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNumberProp } from "@/lib/type-guards";

export type KudosState = { count: number; given: boolean };

export type KudosTarget = { targetType: string; targetId: string };

function isKudosState(v: unknown): v is KudosState {
  return typeof v === "object" && v !== null && hasNumberProp(v, "count") && hasBooleanProp(v, "given");
}

export function kudosKey(target: KudosTarget): string {
  return `${target.targetType}:${target.targetId}`;
}

export async function toggleKudos(targetType: string, targetId: string): Promise<KudosState | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/kudos`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ targetType, targetId }),
    });
    if (!res.ok) return null;
    const json: unknown = await res.json();
    if (typeof json !== "object" || json === null || !("data" in json) || !isKudosState(json.data)) return null;
    return json.data;
  } catch {
    return null;
  }
}

export async function fetchKudosState(targets: KudosTarget[]): Promise<Record<string, KudosState>> {
  if (targets.length === 0) return {};
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/kudos/state`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ targets }),
    });
    if (!res.ok) return {};
    const json: unknown = await res.json();
    if (typeof json !== "object" || json === null || !("data" in json) || typeof json.data !== "object" || json.data === null) {
      return {};
    }
    const out: Record<string, KudosState> = {};
    for (const [key, value] of Object.entries(json.data)) {
      if (isKudosState(value)) out[key] = value;
    }
    return out;
  } catch {
    return {};
  }
}
