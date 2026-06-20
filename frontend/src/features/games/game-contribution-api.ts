import { env } from "@/lib/env";
import { apiFetch } from "@/lib/apiFetch";
import { hasNumberProp, hasStringProp } from "@/lib/type-guards";
import type { InstallStep } from "./install-steps-editor";

export type MyContribution = { id: string; status: string; target: string; stepCount: number; createdAt: string };

export type ContributionInput = {
  gameSlug?: string;
  proposedGameName?: string;
  steps: InstallStep[];
  message?: string;
};

/** Submit a community tutorial contribution (story 31.6). Returns ok. */
export async function submitContribution(input: ContributionInput): Promise<boolean> {
  try {
    const response = await apiFetch(`${env.apiBaseUrl}/game-contributions`, {
      body: JSON.stringify(input),
      headers: { "Content-Type": "application/json" },
      method: "POST",
    });
    return response.ok;
  } catch {
    return false;
  }
}

function isMyContribution(v: unknown): v is MyContribution {
  if (typeof v !== "object" || v === null) return false;
  return (
    hasStringProp(v, "id")
    && hasStringProp(v, "status")
    && hasStringProp(v, "target")
    && hasNumberProp(v, "stepCount")
    && hasStringProp(v, "createdAt")
  );
}

/** List the current user's contributions (story 31.6). */
export async function getMyContributions(): Promise<MyContribution[]> {
  try {
    const response = await apiFetch(`${env.apiBaseUrl}/game-contributions/me`);
    if (!response.ok) return [];

    const payload: unknown = await response.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload)) return [];
    if (!Array.isArray(payload.data) || !payload.data.every(isMyContribution)) return [];

    return payload.data;
  } catch {
    return [];
  }
}
