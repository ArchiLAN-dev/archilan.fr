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

/**
 * Admin update of the generic guide steps. Returns null on success, otherwise the reason.
 *
 * The API answers a 422 with per-step messages ("Étape 2 : le titre est requis."). Returning a bare
 * boolean threw that away and left the caller guessing, so the detail is surfaced instead.
 */
export async function saveArchipelagoGuide(steps: GameStep[]): Promise<string | null> {
  try {
    const response = await apiFetch(`${env.apiBaseUrl}/admin/archipelago-guide`, {
      body: JSON.stringify({ steps }),
      headers: { "Content-Type": "application/json" },
      method: "PUT",
    });
    if (response.ok) return null;

    const payload: unknown = await response.json().catch(() => null);
    const details = extractStepErrors(payload);

    return details ?? `Échec de l'enregistrement (erreur ${response.status}).`;
  } catch {
    return "Impossible de contacter le serveur.";
  }
}

/** The API's own per-step validation messages, joined - or null when the body carries none. */
function extractStepErrors(payload: unknown): string | null {
  if (typeof payload !== "object" || payload === null || !("error" in payload)) return null;
  const error = payload.error;
  if (typeof error !== "object" || error === null || !("details" in error)) return null;
  const details = error.details;
  if (typeof details !== "object" || details === null || !("steps" in details)) return null;
  const steps = details.steps;
  if (!Array.isArray(steps)) return null;

  const messages = steps.filter((m): m is string => typeof m === "string");

  return messages.length > 0 ? messages.join(" ") : null;
}
