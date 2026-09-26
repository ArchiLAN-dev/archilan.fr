import Link from "next/link";
import { AlertTriangle } from "lucide-react";

import { INCIDENT_TYPE_LABELS, type ApworldIncident } from "./admin-apworld-health-api";
import { incidentOwnerLine } from "./apworld-incident-format";

/**
 * On a game's admin page, the incidents its apworld currently has (story 38.3 AC6): what broke, who
 * holds it, and the way to the health page where it is handled.
 */
export function ApworldIncidentBanner({ incidents }: { incidents: ApworldIncident[] }) {
  if (incidents.length === 0) return null;

  return (
    <div className="mb-6 grid gap-2 rounded-lg border border-danger/40 bg-danger/10 p-4 text-sm" role="status">
      {incidents.map((incident) => (
        <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-foreground" key={incident.id}>
          <AlertTriangle aria-hidden className="size-4 text-danger" />
          <span className="font-semibold">{INCIDENT_TYPE_LABELS[incident.type] ?? incident.type}</span>
          <span className="text-muted-foreground">{incident.summary}</span>
          <span className="font-semibold">{incidentOwnerLine(incident)}</span>
        </p>
      ))}
      <Link className="w-fit font-semibold text-foreground underline underline-offset-2 hover:text-accent" href="/admin/sante-apworlds">
        Voir sur la page Santé des apworlds
      </Link>
    </div>
  );
}
