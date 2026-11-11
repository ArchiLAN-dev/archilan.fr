import { renderToStaticMarkup } from "react-dom/server";

import { RegisteredCta } from "@/features/events/event-registration-cta";
import { HubLinkView } from "./hub-link";
import { HubQrSheet, hubUrl } from "./hub-qr-sheet";

/** Story 53.7: the ways into the registrant space. */
describe("the QR code sheet", () => {
  it("opens the registrant space of the event", () => {
    expect(hubUrl("e1")).toMatch(/^https?:\/\/[^/]+\/evenements\/e1\/mon-event$/);
  });

  it("prints the code, the call and the event's title on one sheet", () => {
    const html = renderToStaticMarkup(<HubQrSheet eventTitle="ArchiLAN 4" url="https://archilan.fr/evenements/e1/mon-event" />);

    expect(html).toContain("Perdu ? Scanne-moi");
    expect(html).toContain("ArchiLAN 4");
    expect(html).toContain("data-print-sheet");
    expect(html).toMatch(/<path d="M\d/);
    expect(html).toContain("Télécharger en PNG");
  });
});

describe("RegisteredCta", () => {
  const props = { eventSlug: "archilan-4", registrationId: "r1", confirmed: true };

  it("points a registrant to their space, the registration one click away", () => {
    const html = renderToStaticMarkup(<RegisteredCta {...props} inProgress={false} />);

    expect(html).toContain("Tu es inscrit.");
    expect(html).toContain('href="/evenements/archilan-4/mon-event"');
    expect(html).toContain('href="/evenements/archilan-4/inscription/r1/recap"');
  });

  it("offers the game during the event, to a confirmed registrant only", () => {
    expect(renderToStaticMarkup(<RegisteredCta {...props} inProgress />)).toContain('href="/evenements/archilan-4/inscription/r1/session"');
    const pending = renderToStaticMarkup(<RegisteredCta {...props} confirmed={false} inProgress />);
    expect(pending).toContain('href="/evenements/archilan-4/mon-event"');
    expect(pending).not.toContain("/session");
  });
});

describe("HubLinkView", () => {
  it("leads back to the space", () => {
    expect(renderToStaticMarkup(<HubLinkView eventSlug="archilan-4" />)).toContain('href="/evenements/archilan-4/mon-event"');
  });
});
