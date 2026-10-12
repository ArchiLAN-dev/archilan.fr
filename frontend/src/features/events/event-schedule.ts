import type { EventStatus } from "./event-types";

/** Event times are shown in Paris time wherever the page is rendered (the server runs in UTC). */
const ZONE = "Europe/Paris";

const DAY = new Intl.DateTimeFormat("fr-FR", { timeZone: ZONE, weekday: "long", day: "numeric", month: "long" });
const DAY_YEAR = new Intl.DateTimeFormat("fr-FR", { timeZone: ZONE, weekday: "long", day: "numeric", month: "long", year: "numeric" });
const LONG_DATE = new Intl.DateTimeFormat("fr-FR", { timeZone: ZONE, dateStyle: "long" });
const HOUR = new Intl.DateTimeFormat("fr-FR", { timeZone: ZONE, hour: "2-digit", minute: "2-digit" });
const DAY_KEY = new Intl.DateTimeFormat("en-CA", { timeZone: ZONE, year: "numeric", month: "2-digit", day: "2-digit" });

export type EventTiming = {
  status: "published" | "in-progress" | "completed";
  startsAt: string;
  endsAt: string;
  registrationOpensAt: string;
  registrationClosesAt: string;
  capacity: number;
  confirmedRegistrations: number;
};

/**
 * Story 4.23: where the event stands for a visitor, from the API status, the registration window and the seats. Only
 * `open` offers to register; the registration page checks again anyway (the page itself can be five minutes old).
 */
export function eventStatusAt(event: EventTiming, now: Date): EventStatus {
  if (event.status === "completed") return "completed";
  if (event.status === "in-progress") return "in-progress";

  const time = now.getTime();
  if (time < new Date(event.registrationOpensAt).getTime()) return "upcoming";
  if (time > new Date(event.registrationClosesAt).getTime()) return "closed";
  if (event.confirmedRegistrations >= event.capacity) return "full";
  return "open";
}

/** "21 novembre 2026": the short form for cards and lists. */
export function eventDate(iso: string): string {
  return LONG_DATE.format(new Date(iso));
}

/** "lundi 19 octobre à 00h01". */
export function dayAndHour(iso: string, withYear = false): string {
  const date = new Date(iso);
  return `${(withYear ? DAY_YEAR : DAY).format(date)} à ${hour(date)}`;
}

/**
 * "Samedi 21 novembre 2026, de 10h00 à 22h00" on one day; "Du samedi 21 novembre à 10h00 au dimanche 22 novembre 2026
 * à 18h00" over several.
 */
export function eventSchedule(startsAt: string, endsAt: string): string {
  const start = new Date(startsAt);
  const end = new Date(endsAt);
  if (DAY_KEY.format(start) === DAY_KEY.format(end)) {
    return `${capitalize(DAY_YEAR.format(start))}, de ${hour(start)} à ${hour(end)}`;
  }
  return `Du ${DAY.format(start)} à ${hour(start)} au ${DAY_YEAR.format(end)} à ${hour(end)}`;
}

function hour(date: Date): string {
  return HOUR.format(date).replace(":", "h");
}

function capitalize(text: string): string {
  return text.charAt(0).toUpperCase() + text.slice(1);
}
