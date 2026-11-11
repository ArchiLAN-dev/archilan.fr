import type { EventStatus } from "@/features/events/event-types";

/** Story 53.1: where the event stands for one of its registrants. The API holds the same rules (story 53.2). */
export type EventPhase = "before" | "during" | "after";

export type EventPhaseInput = {
  status: EventStatus;
  startsAt?: string;
  endsAt?: string;
};

/**
 * The admin's status wins (an event started early is `during`, one closed early is `after`); otherwise the dates
 * decide: `during` from the start, `after` past the end.
 */
export function eventPhase(event: EventPhaseInput, now: Date): EventPhase {
  if (event.status === "completed") return "after";
  if (event.status === "in-progress") return "during";

  const time = now.getTime();
  if (event.endsAt !== undefined && time > new Date(event.endsAt).getTime()) return "after";
  if (event.startsAt !== undefined && time >= new Date(event.startsAt).getTime()) return "during";
  return "before";
}

const DAY_KEY = new Intl.DateTimeFormat("en-CA", { timeZone: "Europe/Paris", year: "numeric", month: "2-digit", day: "2-digit" });
const DAY_MS = 24 * 60 * 60 * 1000;

/** Calendar days between two instants, counted in Paris time (a 23h start seen at 1h the day before is "Demain"). */
export function parisDaysBetween(from: Date, to: Date): number {
  const fromDay = Date.parse(DAY_KEY.format(from));
  const toDay = Date.parse(DAY_KEY.format(to));
  return Math.round((toDay - fromDay) / DAY_MS);
}

/** The pill of the hub header: "J-3", "Demain", "Aujourd'hui", "En cours", "Terminé". */
export function eventPhaseLabel(event: EventPhaseInput, now: Date): string {
  const phase = eventPhase(event, now);
  if (phase === "during") return "En cours";
  if (phase === "after") return "Terminé";
  if (event.startsAt === undefined) return "À venir";

  const days = parisDaysBetween(now, new Date(event.startsAt));
  if (days <= 0) return "Aujourd'hui";
  if (days === 1) return "Demain";
  return `J-${days}`;
}
