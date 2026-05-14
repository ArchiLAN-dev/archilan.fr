import { env } from "@/lib/env";
import { apiFetch } from "@/lib/apiFetch";

export async function getCatalogGames(): Promise<string[]> {
  try {
    const res = await fetch(`${env.apiBaseUrl}/catalog-games`, { cache: "no-store" });
    if (!res.ok) return [];
    const json: unknown = await res.json();
    if (
      !json ||
      typeof json !== "object" ||
      !Array.isArray((json as { data?: unknown }).data)
    ) {
      return [];
    }
    return (json as { data: string[] }).data;
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
    if (
      !json ||
      typeof json !== "object" ||
      !Array.isArray((json as { data?: unknown }).data)
    ) {
      return [];
    }
    return (json as { data: GameRequestItem[] }).data;
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
      error: (json as { error?: { message?: string } })?.error?.message,
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
