import type { AdminEvent } from "../admin-events-api";

export type EventStatus = AdminEvent["status"];

export type StatusAction = { label: string; status: EventStatus };

/** Story 3.17: what the list puts forward for an event - the next step of its life. */
export type PrimaryAction =
  | { kind: "transition"; label: string; status: EventStatus }
  | { kind: "recap"; label: string };

/** Story 3.17: the rest of an event's commands, behind the row menu. */
export type MenuAction =
  | { kind: "hub"; label: string }
  | { kind: "transition"; label: string; status: EventStatus }
  | { kind: "feature"; label: string }
  | { kind: "private-access"; label: string };

const LABELS: Record<EventStatus, string> = {
  draft: "Brouillon",
  published: "Publié",
  "in-progress": "En cours",
  completed: "Terminé",
};

export const STATUS_PILL: Record<EventStatus, string> = {
  draft: "border-border bg-surface-2 text-muted-foreground",
  published: "border-accent/40 bg-accent/15 text-accent-text",
  "in-progress": "border-success/40 bg-success/15 text-success",
  completed: "border-border bg-surface-2 text-foreground",
};

const NEXT_STEP: Record<EventStatus, EventStatus | null> = {
  draft: "published",
  published: "in-progress",
  "in-progress": "completed",
  completed: null,
};

export function statusLabel(status: EventStatus): string {
  return LABELS[status];
}

export function transitionActions(status: EventStatus): StatusAction[] {
  const actions: Record<EventStatus, StatusAction[]> = {
    draft: [{ label: "Publier", status: "published" }],
    published: [{ label: "Dépublier", status: "draft" }, { label: "Démarrer", status: "in-progress" }],
    "in-progress": [{ label: "Revenir publié", status: "published" }, { label: "Terminer", status: "completed" }],
    completed: [{ label: "Rouvrir", status: "published" }],
  };
  return actions[status];
}

export function transitionConfirmation(from: EventStatus, to: EventStatus): string {
  if (to === "draft") return "Dépublier cet événement ? Il disparaîtra des pages publiques.";
  if (from === "draft" && to === "published") return "Publier cet événement ? Il deviendra visible sur les pages publiques.";
  return "Confirmer ce changement de statut ?";
}

export function primaryAction(event: AdminEvent): PrimaryAction | null {
  const next = NEXT_STEP[event.status];
  if (next !== null) {
    const action = transitionActions(event.status).find((candidate) => candidate.status === next);
    return action ? { kind: "transition", label: action.label, status: action.status } : null;
  }

  return event.hasRecap ? null : { kind: "recap", label: "Attacher le récap" };
}

export function menuActions(event: AdminEvent): MenuAction[] {
  const primary = primaryAction(event);
  const actions: MenuAction[] = [{ kind: "hub", label: "Ouvrir l'événement" }];

  for (const transition of transitionActions(event.status)) {
    if (primary?.kind === "transition" && primary.status === transition.status) continue;
    actions.push({ kind: "transition", label: transition.label, status: transition.status });
  }
  if (event.status !== "draft") {
    actions.push({ kind: "feature", label: event.featuredOnHome ? "Retirer de l'accueil" : "Mettre en avant" });
  }
  if (!event.isPublic) {
    actions.push({ kind: "private-access", label: event.hasPrivateAccessPassword ? "Changer l'accès privé" : "Configurer l'accès privé" });
  }

  return actions;
}

export function visibilityLabel(event: AdminEvent): string {
  return event.isPublic ? "Public" : event.hasPrivateAccessPassword ? "Privé protégé" : "Privé";
}

export function eventHubHref(eventId: string): string {
  return `/admin/evenements/${eventId}`;
}

export function formatDateShort(value: string): string {
  return new Intl.DateTimeFormat("fr-FR", {
    day: "numeric",
    month: "short",
    year: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(value));
}
