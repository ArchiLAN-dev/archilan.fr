import type { ModerationCommand } from "./admin-users-api";

export type SanctionOption = {
  command: ModerationCommand;
  label: string;
  submitLabel: string;
  /** What the action does, shown under the picker. */
  hint: string;
  danger: boolean;
};

/** Story 39.11 : les actions de la fenêtre de sanction, dans l'ordre où elles s'affichent. */
export const SANCTION_OPTIONS: readonly SanctionOption[] = [
  {
    command: "warn",
    label: "Avertir",
    submitLabel: "Avertir",
    hint: "Le membre est prévenu sur le site, et en MP Discord si son compte est lié.",
    danger: false,
  },
  {
    command: "suspend",
    label: "Suspendre",
    submitLabel: "Suspendre",
    hint: "Sanction jusqu'à la date choisie ; exclusion temporaire du serveur Discord si son compte est lié.",
    danger: false,
  },
  {
    command: "ban",
    label: "Bannir",
    submitLabel: "Bannir",
    hint: "Le membre ne peut plus se connecter ; il est aussi banni du serveur Discord si son compte est lié.",
    danger: true,
  },
  {
    command: "lift",
    label: "Lever",
    submitLabel: "Lever la sanction",
    hint: "Lève la sanction en cours, sur le site et sur Discord.",
    danger: false,
  },
  {
    command: "note",
    label: "Note interne",
    submitLabel: "Enregistrer la note",
    hint: "Visible par le staff seulement (fiche et forum staff) : le membre n'est pas prévenu, et rien ne change pour lui.",
    danger: false,
  },
];

export function sanctionOption(command: ModerationCommand): SanctionOption {
  return SANCTION_OPTIONS.find((option) => option.command === command) ?? SANCTION_OPTIONS[0];
}

export type SanctionDraft = { command: ModerationCommand; reason: string; until: string };

/** Mirrors the server: a reason for every action, and an end date for a suspension. */
export function canSubmitSanction({ command, reason, until }: SanctionDraft): boolean {
  return reason.trim() !== "" && (command !== "suspend" || until !== "");
}

/** The `datetime-local` value as the instant the API expects, for a suspension only. */
export function sanctionUntil(command: ModerationCommand, until: string): string | undefined {
  return command === "suspend" && until !== "" ? new Date(until).toISOString() : undefined;
}
