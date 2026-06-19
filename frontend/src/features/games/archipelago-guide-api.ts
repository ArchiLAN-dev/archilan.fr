import { env } from "@/lib/env";
import { apiFetch } from "@/lib/apiFetch";
import { isGameStep, type GameStep } from "./public-games-api";

/** Public read of the generic "Installer Archipelago" guide steps (story 31.3). */
export async function getArchipelagoGuide(): Promise<GameStep[]> {
  try {
    const response = await fetch(`${env.apiBaseUrl}/archipelago-guide`, { cache: "no-store" });
    if (!response.ok) return [];

    const payload: unknown = await response.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload)) return [];
    const data = payload.data;
    if (typeof data !== "object" || data === null || !("steps" in data)) return [];
    if (!Array.isArray(data.steps) || !data.steps.every(isGameStep)) return [];

    return data.steps;
  } catch {
    return [];
  }
}

/** Admin update of the generic guide steps. */
export async function saveArchipelagoGuide(steps: GameStep[]): Promise<boolean> {
  try {
    const response = await apiFetch(`${env.apiBaseUrl}/admin/archipelago-guide`, {
      body: JSON.stringify({ steps }),
      headers: { "Content-Type": "application/json" },
      method: "PUT",
    });
    return response.ok;
  } catch {
    return false;
  }
}
