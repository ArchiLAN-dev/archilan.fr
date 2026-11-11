import { renderToStaticMarkup } from "react-dom/server";

import { EventForm, fieldErrorsFromPayload, PRACTICAL_INFO_TEMPLATE } from "./admin-event-form";

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
