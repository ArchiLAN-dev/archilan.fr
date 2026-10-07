"use client";

import { useState } from "react";

import type { AdminQuestWeek, AdminQuests } from "./admin-quests-api";

/** Shared by the two tabs of the weekly quests' page (story 41.15). */

export const fieldClass = "min-h-9 rounded-lg border border-border bg-background px-3 text-sm text-foreground";

const weekFormatter = new Intl.DateTimeFormat("fr-FR", { day: "numeric", month: "short", timeZone: "Europe/Paris" });

/** « 5 oct. - 11 oct. » (a week ends on the next Monday, so the Sunday before it closes the range). */
export function weekLabel(week: Pick<AdminQuestWeek, "startsAt" | "endsAt">): string {
  const lastDay = new Date(new Date(week.endsAt).getTime() - 1);
  return `${weekFormatter.format(new Date(week.startsAt))} - ${weekFormatter.format(lastDay)}`;
}

export type ViewProps = { data: AdminQuests; onChange: (error: string | null) => Promise<string | null> };

type StatusMessage = { tone: "ok" | "error"; text: string };

/** A change, its pending state and the line that reports it. */
export function useQuestChange(onChange: ViewProps["onChange"]) {
  const [message, setMessage] = useState<StatusMessage | null>(null);
  const [pending, setPending] = useState(false);

  /** `ok` may be read once the change is done, when the message depends on what it did (story 41.26). */
  async function apply(run: () => Promise<string | null>, ok: string | (() => string)): Promise<boolean> {
    setPending(true);
    const error = await onChange(await run());
    setPending(false);
    setMessage(error === null ? { tone: "ok", text: typeof ok === "string" ? ok : ok() } : { tone: "error", text: error });
    return error === null;
  }

  return { message, pending, apply };
}

export function StatusLine({ message }: { message: StatusMessage | null }) {
  return message ? (
    <p className={`rounded-lg border px-3 py-2 text-sm ${message.tone === "ok" ? "border-success/40 text-success" : "border-danger/40 text-danger"}`} role="status">
      {message.text}
    </p>
  ) : null;
}
