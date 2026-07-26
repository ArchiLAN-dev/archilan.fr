import type { InstallStep } from "@/features/games/install-steps-editor";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

export type GameAvailability = "available" | "unavailable" | "experimental";

export type AdminGame = {
  id: string;
  name: string;
  slug: string;
  description: string;
  archipelagoDescription: string | null;
  coverImageUrl: string | null;
  coverImageAlt: string;
  coverImageCredit: string;
  availability: GameAvailability;
  disabled: boolean;
  disabledMessage: string | null;
  archipelagoGameName: string | null;
  isYamlReady: boolean;
  isApworldReady: boolean;
  apworldHash: string | null;
  apworldUploadedAt: string | null;
  defaultYaml: string | null;
  catalogSheetName: string | null;
  apworldSourceUrl: string | null;
  apworldDeployedVersion: string | null;
  apworldLatestVersion: string | null;
  apworldCheckedAt: string | null;
  apworldReleaseUrl: string | null;
  availabilityLocked: boolean;
  igdbId: number | null;
  platforms: string[];
  installSteps: InstallStep[];
  updateStatus: "update_available" | "up_to_date" | "unknown" | "not_tracked";
  // Admin-only free-text notes (story 3.12). Present only in the admin detail payload, never public.
  adminNotes: string | null;
};

// Discriminated result: keeps the editor's four failure screens distinct. Never throws
// (AC-API2) - the old effect was one-shot too.
export type AdminGameResult =
  | { kind: "ready"; game: AdminGame }
  | { kind: "denied" }
  | { kind: "not_found" }
  | { kind: "error" };

export async function fetchAdminGame(gameId: string): Promise<AdminGameResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/games/${gameId}`);

    if (res.status === 401 || res.status === 403) {
      return { kind: "denied" };
    }
    if (res.status === 404) {
      return { kind: "not_found" };
    }
    if (!res.ok) {
      return { kind: "error" };
    }

    const payload: unknown = await res.json();
    return isAdminGamePayload(payload) ? { kind: "ready", game: payload.data } : { kind: "error" };
  } catch {
    return { kind: "error" };
  }
}

// Exported for the editor's mutation handlers, which parse the PATCH/POST responses with the
// same guard before pushing the updated game back into the query cache.
export function isAdminGamePayload(payload: unknown): payload is { data: AdminGame } {
  if (typeof payload !== "object" || payload === null || !("data" in payload)) return false;
  const data: unknown = payload.data;
  if (typeof data !== "object" || data === null) return false;
  return "id" in data && "name" in data;
}
