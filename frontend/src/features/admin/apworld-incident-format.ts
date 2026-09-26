import type { ApworldIncident } from "./admin-apworld-health-api";

/** Wording shared by the health page and the banner of a game's page (story 38.3). */

const DATE_FORMAT = new Intl.DateTimeFormat("fr-FR", {
  dateStyle: "short",
  timeStyle: "short",
  timeZone: "Europe/Paris",
});

export function formatIncidentDate(iso: string | null): string {
  if (iso === null) return "";
  const date = new Date(iso);
  return Number.isNaN(date.getTime()) ? "" : DATE_FORMAT.format(date);
}

/** Who holds the incident, or how it ended: the line the team reads first. */
export function incidentOwnerLine(incident: ApworldIncident): string {
  switch (incident.status) {
    case "open":
      return "Personne ne s'en occupe";
    case "acknowledged":
      return incident.acknowledgedBy !== null
        ? `${incident.acknowledgedBy.displayName} s'en occupe depuis le ${formatIncidentDate(incident.acknowledgedAt)}`
        : "Pris en charge";
    case "resolved":
      return incident.closedAutomatically || incident.closedBy === null
        ? `Résolu automatiquement le ${formatIncidentDate(incident.closedAt)}`
        : `Résolu par ${incident.closedBy.displayName} le ${formatIncidentDate(incident.closedAt)}`;
    case "ignored":
      return incident.closedBy !== null
        ? `Ignoré par ${incident.closedBy.displayName} le ${formatIncidentDate(incident.closedAt)}`
        : `Ignoré, verdict forcé par un admin, le ${formatIncidentDate(incident.closedAt)}`;
    default:
      return "";
  }
}
