import type { EventPhase } from "./event-phase";

/** The sections of the registrant space; `links` is the pair "Mon inscription" and "Les guides". */
export type HubSectionKey = "checklist" | "practicalInfo" | "tools" | "relive" | "links";

/**
 * Story 53.6: what comes first at each moment. Before, getting ready; during, playing (the checklist is still there,
 * folded); after, living it again: the checklist and the practical info are gone, the tools keep the surveys and the
 * pelles only.
 */
export function hubSectionOrder(phase: EventPhase): HubSectionKey[] {
  switch (phase) {
    case "before":
      return ["checklist", "practicalInfo", "tools", "links"];
    case "during":
      return ["tools", "practicalInfo", "checklist", "links"];
    case "after":
      return ["relive", "tools", "links"];
  }
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
