/**
 * Wording of an apworld's update status (stories 14.5 and 38.5), shared by the catalogue page and the
 * game page so they never disagree again.
 */

export const APWORLD_UPDATE_STATUSES = ["update_available", "up_to_date", "unknown", "undetermined", "not_tracked"] as const;

export type ApworldUpdateStatus = (typeof APWORLD_UPDATE_STATUSES)[number];

export type ApworldUpdateStatusTone = "warning" | "success" | "muted" | "faint";

const LABELS: Record<ApworldUpdateStatus, string> = {
  update_available: "Mise à jour disponible",
  up_to_date: "À jour",
  unknown: "Non vérifié",
  // A tag without a readable version number: shown, never acted upon by the automatic update.
  undetermined: "Version illisible",
  not_tracked: "Non suivi",
};

const TONES: Record<ApworldUpdateStatus, ApworldUpdateStatusTone> = {
  update_available: "warning",
  up_to_date: "success",
  unknown: "muted",
  undetermined: "warning",
  not_tracked: "faint",
};

function isKnownStatus(status: string): status is ApworldUpdateStatus {
  return (APWORLD_UPDATE_STATUSES as readonly string[]).includes(status);
}

export function updateStatusLabel(status: string): string {
  // An unexpected status is shown as such, never passed off as "Non suivi".
  return isKnownStatus(status) ? LABELS[status] : `Statut inconnu (${status})`;
}

export function updateStatusTone(status: string): ApworldUpdateStatusTone {
  return isKnownStatus(status) ? TONES[status] : "muted";
}
