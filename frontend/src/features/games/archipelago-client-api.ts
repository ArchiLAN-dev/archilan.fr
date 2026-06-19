import { env } from "@/lib/env";
import { apiFetch } from "@/lib/apiFetch";
import { hasStringProp } from "@/lib/type-guards";

export type ArchipelagoClient = { version: string; downloadUrl: string };

function isArchipelagoClient(v: unknown): v is ArchipelagoClient {
  if (typeof v !== "object" || v === null) return false;
  return hasStringProp(v, "version") && hasStringProp(v, "downloadUrl");
}

/** Public read of the pinned Archipelago client version + download URL (story 31.8). */
export async function getArchipelagoClient(): Promise<ArchipelagoClient | null> {
  try {
    const response = await fetch(`${env.apiBaseUrl}/archipelago-client`, { cache: "no-store" });
    if (!response.ok) return null;

    const payload: unknown = await response.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload)) return null;
    return isArchipelagoClient(payload.data) ? payload.data : null;
  } catch {
    return null;
  }
}

/** Admin update of the Archipelago client info. */
export async function saveArchipelagoClient(version: string, downloadUrl: string): Promise<boolean> {
  try {
    const response = await apiFetch(`${env.apiBaseUrl}/admin/archipelago-client`, {
      body: JSON.stringify({ version, downloadUrl }),
      headers: { "Content-Type": "application/json" },
      method: "PUT",
    });
    return response.ok;
  } catch {
    return false;
  }
}
