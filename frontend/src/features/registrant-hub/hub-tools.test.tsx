import { renderToStaticMarkup } from "react-dom/server";

import type { SessionConnectionResult, SessionPayload } from "@/features/events/events-api";
import type { Wallet } from "@/features/wallet/wallet-api";
import { checklistLine } from "./registrant-checklist";
import { HubPellesCard, HubSessionCard } from "./hub-tools";

const sessionPath = "/evenements/archilan-4/inscription/r1/session";

function connection(session: SessionPayload | null): SessionConnectionResult {
  return {
    kind: "success",
    data: { eventId: "e1", session, slots: [{ slotName: "Jean_OoT", slotOrder: 1, gameId: "g1", gameName: "Ocarina of Time", slotId: "s1", coPlayed: false }] },
  };
}

function session(status: string, overrides: Partial<SessionPayload> = {}): SessionPayload {
  return { id: "run1", status, host: null, port: null, connectionUri: null, password: null, ...overrides };
}

/** Story 53.3: the member's game, in the hub. */
describe("HubSessionCard", () => {
  it("waits for the game before the event", () => {
    const html = renderToStaticMarkup(<HubSessionCard result={connection(null)} sessionPath={sessionPath} />);
    expect(html).toContain("Pas encore de partie");
    expect(html).toContain(`href="${sessionPath}"`);
  });

  it("says the game is being built", () => {
    expect(renderToStaticMarkup(<HubSessionCard result={connection(session("generating"))} sessionPath={sessionPath} />)).toContain(
      "Ta partie se prépare",
    );
  });

  it("gives the address once the game runs", () => {
    const html = renderToStaticMarkup(
      <HubSessionCard
        result={connection(session("running", { host: "archilan.fr", port: 35012, connectionUri: "wss://archilan.fr:35012" }))}
        sessionPath={sessionPath}
      />,
    );
    // ConnectionFields masks the values until shown; the copy buttons prove the address is there.
    expect(html).toContain("Copier port");
    expect(html).toContain("Jean_OoT");
  });

  it("closes with the game", () => {
    expect(renderToStaticMarkup(<HubSessionCard result={connection(session("finished"))} sessionPath={sessionPath} />)).toContain(
      "Ta partie est terminée",
    );
    expect(renderToStaticMarkup(<HubSessionCard result={connection(session("crashed"))} sessionPath={sessionPath} />)).toContain(
      "interrompue",
    );
  });

  it("stays out while loading or without a game to show", () => {
    expect(renderToStaticMarkup(<HubSessionCard result={null} sessionPath={sessionPath} />)).toBe("");
    expect(renderToStaticMarkup(<HubSessionCard result={{ kind: "not_found" }} sessionPath={sessionPath} />)).toBe("");
  });
});

describe("HubPellesCard", () => {
  const wallet: Wallet = {
    gold: 12,
    events: [{ eventId: "e1", eventTitle: "ArchiLAN 4", balance: 3 }],
    bronze: [],
    history: { items: [], page: 1, perPage: 20, total: 0 },
  };

  it("shows the gold and the pelles of this event", () => {
    const html = renderToStaticMarkup(<HubPellesCard eventId="e1" wallet={wallet} />);
    expect(html).toContain("12</span> en or");
    expect(html).toContain("3</span> pour cet événement");
    expect(html).toContain('href="/compte/pelles"');
  });

  it("leaves out the event when it has no pelles", () => {
    expect(renderToStaticMarkup(<HubPellesCard eventId="other" wallet={wallet} />)).not.toContain("pour cet événement");
  });

  it("stays out without a wallet", () => {
    expect(renderToStaticMarkup(<HubPellesCard eventId="e1" wallet={null} />)).toBe("");
  });
});

describe("the presence line of the checklist", () => {
  it("leads to the presence card of the hub", () => {
    expect(checklistLine({ key: "presence", state: "optional", required: false }, { eventSlug: "archilan-4", registrationId: "r1" })?.href).toBe(
      "/evenements/archilan-4/mon-event#presence",
    );
  });
});
