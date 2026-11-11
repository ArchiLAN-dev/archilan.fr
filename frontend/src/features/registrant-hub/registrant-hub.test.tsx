import { renderToStaticMarkup } from "react-dom/server";

import { RegistrantHubSection, RegistrantHubView, type RegistrantHubEvent } from "./registrant-hub";

const event: RegistrantHubEvent = {
  id: "e1",
  slug: "archilan-4",
  title: "ArchiLAN 4",
  status: "closed",
  startsAt: "2026-11-21T09:00:00Z",
  endsAt: "2026-11-22T17:00:00Z",
  location: "Salle des fêtes, Clermont-Ferrand",
};

const before = new Date("2026-11-18T10:00:00Z");

/** Story 53.1: the header and the frame of the registrant hub. */
describe("RegistrantHubView", () => {
  it("shows the event, the countdown and a confirmed registration", () => {
    const html = renderToStaticMarkup(
      <RegistrantHubView event={event} now={before} registration={{ registrationId: "r1", confirmed: true }} />,
    );

    expect(html).toContain("ArchiLAN 4");
    expect(html).toContain("J-3");
    expect(html).toContain("Du samedi 21 novembre à 10h00 au dimanche 22 novembre 2026 à 18h00");
    expect(html).toContain("Salle des fêtes, Clermont-Ferrand");
    expect(html).toContain("Inscription confirmée");
    expect(html).toContain('href="/evenements/archilan-4/inscription/r1/session"');
    expect(html).toContain('href="/evenements/archilan-4"');
  });

  it("asks an unconfirmed registrant to confirm", () => {
    const html = renderToStaticMarkup(
      <RegistrantHubView event={event} now={before} registration={{ registrationId: "r1", confirmed: false }} />,
    );

    expect(html).toContain("Inscription à confirmer");
    expect(html).toContain('href="/evenements/archilan-4/inscription/r1/recap"');
    expect(html).not.toContain("/inscription/r1/session");
  });

  it("follows the moment of the event", () => {
    const during = renderToStaticMarkup(
      <RegistrantHubView event={event} now={new Date("2026-11-21T12:00:00Z")} registration={{ registrationId: "r1", confirmed: true }} />,
    );
    expect(during).toContain("En cours");

    const after = renderToStaticMarkup(
      <RegistrantHubView event={{ ...event, status: "completed" }} now={before} registration={{ registrationId: "r1", confirmed: true }} />,
    );
    expect(after).toContain("Terminé");
  });

  it("leaves out a missing venue", () => {
    const html = renderToStaticMarkup(
      <RegistrantHubView event={{ ...event, location: "" }} now={before} registration={{ registrationId: "r1", confirmed: true }} />,
    );
    expect(html).not.toContain("Clermont");
  });
});

describe("RegistrantHubSection", () => {
  it("renders nothing without content", () => {
    expect(renderToStaticMarkup(<RegistrantHubSection title="Vide" />)).toBe("");
    expect(renderToStaticMarkup(<RegistrantHubSection title="Vide">{null}{false}</RegistrantHubSection>)).toBe("");
  });

  it("renders its title and content", () => {
    const html = renderToStaticMarkup(<RegistrantHubSection title="Infos pratiques"><p>Wifi</p></RegistrantHubSection>);
    expect(html).toContain("Infos pratiques");
    expect(html).toContain("<p>Wifi</p>");
  });
});
