import type { EventPhase } from "./event-phase";

/** Story 53.10: the blocks of the main column of the registrant space; the side column holds the small tools. */
export type HubMainKey = "game" | "participation" | "practicalInfo" | "relive";

/**
 * Story 53.6, laid out as a dashboard by story 53.10: what the main column shows at each moment. Before the event,
 * the game, what the member tells the organisers (presence, stream), then the practical info; during it, the
 * practical info (where, wifi) comes before the forms. After, living it again.
 */
export function hubMainSections(phase: EventPhase): HubMainKey[] {
  if (phase === "after") return ["relive"];
  return phase === "during" ? ["game", "practicalInfo", "participation"] : ["game", "participation", "practicalInfo"];
}

export type ReliveLink = {
  key: "results" | "recap" | "vod" | "photos";
  label: string;
  href: string;
  external?: boolean;
};

type ReliveInput = {
  eventSlug: string;
  /** The member's game, once finished: its recap page. */
  recapSessionId: string | null;
  vodUrl: string | null;
  hasPhotos: boolean;
};

/** Story 53.6: the ways back into the event, only those that exist. */
export function reliveLinks({ eventSlug, recapSessionId, vodUrl, hasPhotos }: ReliveInput): ReliveLink[] {
  const links: ReliveLink[] = [{ key: "results", label: "Les résultats", href: `/evenements/${eventSlug}/resultats` }];
  if (recapSessionId !== null) links.push({ key: "recap", label: "Le récap de ma partie", href: `/parties/${recapSessionId}` });
  if (vodUrl !== null && vodUrl !== "") links.push({ key: "vod", label: "La rediffusion", href: vodUrl, external: true });
  if (hasPhotos) links.push({ key: "photos", label: "Les photos", href: `/evenements/${eventSlug}#photos` });
  return links;
}

/** Statuses of a game that is over: its recap page exists. */
export const FINISHED_SESSION_STATUSES = ["stopped", "finished"];
