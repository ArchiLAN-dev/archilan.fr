import { env } from "@/lib/env";
import { apiFetch } from "@/lib/apiFetch";
import { hasBooleanProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

function isStringArray(v: unknown[]): v is string[] {
  return v.every((item): item is string => typeof item === "string");
}

function isGameRequestItem(v: unknown): v is GameRequestItem {
  if (typeof v !== "object" || v === null) return false;
  return (
    hasStringProp(v, "normalizedName") &&
    hasStringProp(v, "displayName") &&
    hasNumberProp(v, "voteCount") &&
    hasBooleanProp(v, "hasVoted")
  );
}

export async function getCatalogGames(): Promise<string[]> {
  try {
    const res = await fetch(`${env.apiBaseUrl}/catalog-games`, { cache: "no-store" });
    if (!res.ok) return [];
    const json: unknown = await res.json();
    if (typeof json !== "object" || json === null) return [];
    if (!("data" in json) || !Array.isArray(json.data)) return [];
    if (!isStringArray(json.data)) return [];
    return json.data;
  } catch {
    return [];
  }
}

export type GameRequestItem = {
  normalizedName: string;
  displayName: string;
  voteCount: number;
  hasVoted: boolean;
};

export async function getGameRequests(): Promise<GameRequestItem[]> {
  try {
    const res = await fetch(`${env.apiBaseUrl}/game-requests`, {
      cache: "no-store",
      credentials: "include",
    });
    if (!res.ok) return [];
    const json: unknown = await res.json();
    if (typeof json !== "object" || json === null) return [];
    if (!("data" in json) || !Array.isArray(json.data)) return [];
    if (!json.data.every(isGameRequestItem)) return [];
    return json.data;
  } catch {
    return [];
  }
}

export async function submitGameRequest(
  gameName: string,
): Promise<{ ok: boolean; alreadyVoted: boolean; error?: string }> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/game-requests`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ gameName }),
    });
    if (res.status === 201) return { ok: true, alreadyVoted: false };
    if (res.status === 409) return { ok: false, alreadyVoted: true };
    const json = await res.json().catch(() => ({}));
    return {
      ok: false,
      alreadyVoted: false,
      error: json?.error?.message,
    };
  } catch {
    return { ok: false, alreadyVoted: false, error: "Erreur réseau." };
  }
}

export async function cancelGameRequest(normalizedName: string): Promise<boolean> {
  try {
    const res = await apiFetch(
      `${env.apiBaseUrl}/game-requests/${encodeURIComponent(normalizedName)}`,
      { method: "DELETE" },
    );
    return res.ok;
  } catch {
    return false;
  }
}
