import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

/** "Type de signalement" - which part of the profile (story 30.28). Mirrors the backend ReportCategory. */
export const REPORT_CATEGORIES = [
  { value: "avatar", label: "Photo de profil" },
  { value: "display_name", label: "Pseudo" },
  { value: "bio", label: "Biographie" },
  { value: "social_link", label: "Lien externe" },
  { value: "other", label: "Autre" },
] as const;

/** "Contenu problématique" - drives severity (story 30.28). Mirrors the backend ReportProblem. */
export const REPORT_PROBLEMS = [
  { value: "nudity", label: "Nudité / contenu sexuel" },
  { value: "violence", label: "Violence" },
  { value: "hate", label: "Haine / discrimination" },
  { value: "harassment", label: "Harcèlement" },
  { value: "spam", label: "Spam / publicité" },
  { value: "other", label: "Autre" },
] as const;

export type ReportProfileInput = { category: string; problem: string; comment: string };

export type ReportResult = "ok" | "forbidden" | "invalid" | "not_found" | "error";

/**
 * Report another member's profile (story 30.28). Returns a coarse status so the dialog can show a
 * meaningful message (self-report refused, invalid, etc.).
 */
export async function reportProfile(slug: string, input: ReportProfileInput): Promise<ReportResult> {
  try {
    const comment = input.comment.trim();
    const res = await apiFetch(`${env.apiBaseUrl}/community/profiles/${encodeURIComponent(slug)}/report`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        category: input.category,
        problem: input.problem,
        comment: comment === "" ? null : comment,
      }),
    });

    if (res.status === 204) return "ok";
    if (res.status === 403) return "forbidden";
    if (res.status === 422) return "invalid";
    if (res.status === 404) return "not_found";
    return "error";
  } catch {
    return "error";
  }
}
