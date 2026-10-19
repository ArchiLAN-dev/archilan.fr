import { hasBooleanProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

/**
 * Story 38.18: a pass checking the GitHub-tracked apworlds for updates, queued by the catalogue page's button or
 * run at night by the worker.
 */
export type ApworldUpdateCheckRun = {
  id: string;
  status: "queued" | "running" | "done" | "failed";
  trigger: "button" | "nightly";
  queuedAt: string;
  startedAt: string | null;
  finishedAt: string | null;
  total: number | null;
  checked: number;
  failed: number;
  rateLimitHit: boolean;
  updatesAvailable: number;
  error: string | null;
};

const STATUSES = new Set(["queued", "running", "done", "failed"]);
const TRIGGERS = new Set(["button", "nightly"]);

export function isApworldUpdateCheckRun(v: unknown): v is ApworldUpdateCheckRun {
  if (typeof v !== "object" || v === null) return false;
  return (
    hasStringProp(v, "id") &&
    hasStringProp(v, "status") && STATUSES.has(v.status) &&
    hasStringProp(v, "trigger") && TRIGGERS.has(v.trigger) &&
    hasStringProp(v, "queuedAt") &&
    hasNullableStringProp(v, "startedAt") &&
    hasNullableStringProp(v, "finishedAt") &&
    "total" in v && (v.total === null || typeof v.total === "number") &&
    hasNumberProp(v, "checked") &&
    hasNumberProp(v, "failed") &&
    hasBooleanProp(v, "rateLimitHit") &&
    hasNumberProp(v, "updatesAvailable") &&
    hasNullableStringProp(v, "error")
  );
}

/** Queued or running: the button stays off and the page keeps polling. */
export function isUpdateCheckActive(run: ApworldUpdateCheckRun | null | undefined): boolean {
  return run?.status === "queued" || run?.status === "running";
}

function plural(n: number, one: string, many: string): string {
  return `${n} ${n > 1 ? many : one}`;
}

function when(iso: string | null): string {
  if (iso === null) return "";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "";
  return ` le ${date.toLocaleDateString("fr-FR", { day: "numeric", month: "long" })} à ${date.toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit" })}`;
}

/** The line the catalogue page shows for the last pass. */
export function describeUpdateCheck(run: ApworldUpdateCheckRun): { tone: "info" | "success" | "warning" | "error"; text: string } {
  const origin = run.trigger === "nightly" ? "de nuit" : "lancée depuis cette page";

  switch (run.status) {
    case "queued":
      return { tone: "info", text: "Vérification lancée en arrière-plan : elle démarre dès que le worker est libre." };
    case "running": {
      const progress = run.total === null ? "" : ` : ${run.checked + run.failed} / ${plural(run.total, "jeu", "jeux")}`;
      return { tone: "info", text: `Vérification ${origin} en cours${progress}.` };
    }
    case "failed":
      return { tone: "error", text: `La dernière vérification ${origin} a échoué${when(run.finishedAt)} : ${run.error ?? "erreur inconnue"}.` };
    case "done": {
      const parts = [plural(run.checked, "jeu vérifié", "jeux vérifiés")];
      if (run.failed > 0) parts.push(plural(run.failed, "en échec", "en échec"));
      parts.push(run.updatesAvailable === 0 ? "aucune mise à jour" : plural(run.updatesAvailable, "mise à jour disponible", "mises à jour disponibles"));
      const limit = run.rateLimitHit ? " Limite GitHub atteinte : la prochaine vérification reprendra aux jeux restants." : "";
      return {
        tone: run.rateLimitHit || run.failed > 0 ? "warning" : "success",
        text: `Dernière vérification ${origin}${when(run.finishedAt)} : ${parts.join(", ")}.${limit}`,
      };
    }
  }
}
