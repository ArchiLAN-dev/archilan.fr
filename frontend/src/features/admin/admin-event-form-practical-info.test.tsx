import { renderToStaticMarkup } from "react-dom/server";

import { EventForm, fieldErrorsFromPayload, PRACTICAL_INFO_TEMPLATE } from "./admin-event-form";
import { isMediaAgentConfig, mediaAgentEnvLines } from "./admin-events-api";

/** Story 53.4: the registrants-only practical information in the event form. */
describe("event form practical info", () => {
  it("offers the template on an empty field", () => {
    const html = renderToStaticMarkup(<EventForm event={null} mode="create" onSubmit={async () => undefined} />);

    expect(html).toContain("Infos pratiques (inscrits seulement)");
    expect(html).toContain("Partir du modèle");
  });

  it("starts from the saved text", () => {
    const html = renderToStaticMarkup(
      <EventForm
        event={{
          id: "e1",
          title: "ArchiLAN 4",
          description: "Desc",
          coverImageUrl: null,
          coverImageKey: null,
          photoGallery: [],
          startsAt: "2026-11-21T09:00:00Z",
          endsAt: "2026-11-22T17:00:00Z",
          venue: "Clermont",
          capacity: 40,
          confirmedRegistrations: 0,
          registrationOpensAt: "2026-10-01T00:00:00Z",
          registrationClosesAt: "2026-11-20T00:00:00Z",
          isPublic: true,
          helloassoFormSlug: null,
          practicalInfo: "## Wifi ArchiLAN",
        }}
        mode="edit"
        onSubmit={async () => undefined}
      />,
    );

    expect(html).toMatch(/name="practicalInfo"[^>]*value="## Wifi ArchiLAN"/);
    expect(html).not.toContain("Partir du modèle");
  });

  it("names the organisers' headings", () => {
    expect(PRACTICAL_INFO_TEMPLATE).toContain("## Accès");
    expect(PRACTICAL_INFO_TEMPLATE).toContain("## Questions fréquentes");
  });

  it("reads the API refusal of a too long text", () => {
    const errors = fieldErrorsFromPayload({ error: { details: { practicalInfo: ["Les infos pratiques ne peuvent pas dépasser 10000 caractères."] } } });
    expect(errors.practicalInfo).toBe("Les infos pratiques ne peuvent pas dépasser 10000 caractères.");
  });
});

/** Story 53.8: the mail of the day before, on by default, switchable per event. */
describe("event form eve reminder", () => {
  const saved = {
    id: "e1",
    title: "ArchiLAN 4",
    description: "Desc",
    coverImageUrl: null,
    coverImageKey: null,
    photoGallery: [],
    startsAt: "2026-11-21T09:00:00Z",
    endsAt: "2026-11-22T17:00:00Z",
    venue: "Clermont",
    capacity: 40,
    confirmedRegistrations: 0,
    registrationOpensAt: "2026-10-01T00:00:00Z",
    registrationClosesAt: "2026-11-20T00:00:00Z",
    isPublic: true,
    helloassoFormSlug: null,
  };
  const checkbox = /<input[^>]*name="eveReminderEnabled"[^>]*>/;

  it("is on for a new event", () => {
    const html = renderToStaticMarkup(<EventForm event={null} mode="create" onSubmit={async () => undefined} />);
    expect(html).toContain("Envoyer un mail la veille aux inscrits confirmés");
    expect(html.match(checkbox)?.[0]).toContain("checked");
  });

  it("follows the saved choice", () => {
    const off = renderToStaticMarkup(<EventForm event={{ ...saved, eveReminderEnabled: false }} mode="edit" onSubmit={async () => undefined} />);
    expect(off.match(checkbox)?.[0]).not.toContain("checked");
    const older = renderToStaticMarkup(<EventForm event={saved} mode="edit" onSubmit={async () => undefined} />);
    expect(older.match(checkbox)?.[0]).toContain("checked");
  });
});

describe("story 47.5 - local broadcasting server", () => {
  const saved = {
    id: "e1",
    title: "ArchiLAN 5",
    description: "Desc",
    coverImageUrl: null,
    coverImageKey: null,
    photoGallery: [],
    startsAt: "2026-11-21T09:00:00Z",
    endsAt: "2026-11-22T17:00:00Z",
    venue: "Clermont",
    capacity: 40,
    confirmedRegistrations: 0,
    registrationOpensAt: "2026-10-01T00:00:00Z",
    registrationClosesAt: "2026-11-20T00:00:00Z",
    isPublic: true,
    helloassoFormSlug: null,
  };
  const field = /<input[^>]*name="mediaServerHost"[^>]*>/;

  it("is empty for a new event and shows the saved server", () => {
    const blank = renderToStaticMarkup(<EventForm event={null} mode="create" onSubmit={async () => undefined} />);
    expect(blank).toContain("Serveur média local");
    expect(blank.match(field)?.[0]).toContain('value=""');

    const set = renderToStaticMarkup(<EventForm event={{ ...saved, mediaServerHost: "media.lan.archilan.fr" }} mode="edit" onSubmit={async () => undefined} />);
    expect(set.match(field)?.[0]).toContain('value="media.lan.archilan.fr"');
  });

  it("offers the régie agent settings once the event has a media server (story 47.6)", () => {
    expect(renderToStaticMarkup(<EventForm event={saved} mode="edit" onSubmit={async () => undefined} />)).not.toContain("Configuration de l&#x27;agent");
    const set = renderToStaticMarkup(<EventForm event={{ ...saved, mediaServerHost: "media.lan.archilan.fr" }} mode="edit" onSubmit={async () => undefined} />);
    expect(set).toContain("Configuration de l&#x27;agent");
  });

  it("writes the agent lines against the site root (story 47.6)", () => {
    const config = { eventId: "e1", secret: "abc" };
    expect(mediaAgentEnvLines("https://archilan.fr/api/v1", config)).toBe("ARCHILAN_API_URL=https://archilan.fr\nARCHILAN_EVENT_ID=e1\nARCHILAN_AGENT_SECRET=abc");
    expect(mediaAgentEnvLines("http://localhost:8000/api/v1/", config).split("\n")[0]).toBe("ARCHILAN_API_URL=http://localhost:8000");
    expect(isMediaAgentConfig({ eventId: "e1", secret: 3 })).toBe(false);
  });

  it("maps the API error to the field", () => {
    expect(fieldErrorsFromPayload({ error: { details: { mediaServerHost: ["Le serveur média est un nom d'hôte seul."] } } }).mediaServerHost).toBe("Le serveur média est un nom d'hôte seul.");
  });
});
