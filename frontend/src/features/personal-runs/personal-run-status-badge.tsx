import type { PersonalRunStatus } from "./types";

type BadgeConfig = {
  label: string;
  className: string;
  pulse?: boolean;
};

const STATUS_CONFIG: Record<PersonalRunStatus, BadgeConfig> = {
  draft: {
    label: "Brouillon",
    className:
      "border-border bg-surface text-muted-foreground",
  },
  starting: {
    label: "Démarrage…",
    className:
      "border-[color:var(--color-accent-warm)]/40 bg-[color:var(--color-accent-warm)]/10 text-[color:var(--color-accent-warm)]",
    pulse: true,
  },
  active: {
    label: "En cours",
    className:
      "border-[color:var(--color-success)]/40 bg-[color:var(--color-success)]/10 text-[color:var(--color-success)]",
  },
  stopping: {
    label: "Arrêt…",
    className:
      "border-[color:var(--color-accent-warm)]/40 bg-[color:var(--color-accent-warm)]/10 text-[color:var(--color-accent-warm)]",
    pulse: true,
  },
  idle: {
    label: "En pause",
    className:
      "border-[color:var(--color-accent-warm)]/40 bg-[color:var(--color-accent-warm)]/10 text-[color:var(--color-accent-warm)]",
  },
  restarting: {
    label: "Redémarrage…",
    className:
      "border-[color:var(--color-accent-warm)]/40 bg-[color:var(--color-accent-warm)]/10 text-[color:var(--color-accent-warm)]",
    pulse: true,
  },
  completed: {
    label: "Terminée",
    className:
      "border-[color:var(--color-accent)]/40 bg-[color:var(--color-accent)]/10 text-accent-text",
  },
  cancelled: {
    label: "Annulée",
    className:
      "border-[color:var(--color-danger)]/40 bg-[color:var(--color-danger)]/10 text-[color:var(--color-danger)]",
  },
};

export function PersonalRunStatusBadge({ status }: { status: PersonalRunStatus }) {
  const config = STATUS_CONFIG[status];

  return (
    <span
      className={[
        "inline-flex items-center gap-1.5 rounded border px-2 py-0.5 text-xs font-medium",
        config.className,
      ].join(" ")}
    >
      {config.pulse && (
        <span className="relative flex size-1.5 shrink-0">
          <span className="absolute inline-flex size-full animate-ping rounded-full bg-current opacity-75" />
          <span className="relative inline-flex size-1.5 rounded-full bg-current" />
        </span>
      )}
      {config.label}
    </span>
  );
}
