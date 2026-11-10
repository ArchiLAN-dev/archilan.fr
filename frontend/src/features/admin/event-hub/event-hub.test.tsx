import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderToStaticMarkup } from "react-dom/server";

import { EventList } from "../admin-event-dashboard";
import type { AdminEvent, AdminEventsResult } from "../admin-events-api";
import { AdminEventHub, AdminEventHubView, hubSections } from "./admin-event-hub";
import { AdminEventNavView, isCurrentSection } from "./admin-event-nav";
import { menuActions, primaryAction } from "./event-status";

jest.mock("next/navigation", () => ({
  usePathname: () => "/admin/evenements/e1",
  useRouter: () => ({ push: () => undefined }),
  notFound: () => {
    throw new Error("NEXT_NOT_FOUND");
  },
}));

function adminEvent(overrides: Partial<AdminEvent> = {}): AdminEvent {
  return {
    id: "e1",
    title: "ArchiLAN 4",
    description: "",
    coverImageUrl: null,
    photoGallery: [],
    status: "published",
    startsAt: "2026-11-13T17:00:00+00:00",
    endsAt: "2026-11-15T17:00:00+00:00",
    venue: "Clermont-Ferrand",
    capacity: 40,
    confirmedRegistrations: 23,
    isAtCapacity: false,
    registrationOpensAt: "2026-10-01T00:00:00+00:00",
    registrationClosesAt: "2026-11-10T00:00:00+00:00",
    isPublic: true,
    visibility: "public",
    hasPrivateAccessPassword: false,
    gameSelectionEnabled: true,
    vodUrl: null,
    recapPostSlug: null,
    hasRecap: false,
    featuredOnHome: false,
    createdAt: "2026-09-01T00:00:00+00:00",
    updatedAt: "2026-09-01T00:00:00+00:00",
    ...overrides,
  };
}

const noop = () => {};

function render(node: React.ReactNode): string {
  return renderToStaticMarkup(<QueryClientProvider client={new QueryClient()}>{node}</QueryClientProvider>);
}

describe("the next step of an event (story 3.17)", () => {
  it("follows the life of the event", () => {
    expect(primaryAction(adminEvent({ status: "draft" }))).toEqual({ kind: "transition", label: "Publier", status: "published" });
    expect(primaryAction(adminEvent({ status: "published" }))).toEqual({ kind: "transition", label: "Démarrer", status: "in-progress" });
    expect(primaryAction(adminEvent({ status: "in-progress" }))).toEqual({ kind: "transition", label: "Terminer", status: "completed" });
    expect(primaryAction(adminEvent({ status: "completed" }))).toEqual({ kind: "recap", label: "Attacher le récap" });
    expect(primaryAction(adminEvent({ status: "completed", hasRecap: true }))).toBeNull();
  });

  it("leaves the other commands to the menu, never the next step twice", () => {
    const labels = (event: AdminEvent) => menuActions(event).map((action) => action.label);

    expect(labels(adminEvent({ status: "draft" }))).toEqual(["Ouvrir l'événement"]);
    expect(labels(adminEvent({ status: "published" }))).toEqual(["Ouvrir l'événement", "Dépublier", "Mettre en avant"]);
    expect(labels(adminEvent({ status: "in-progress", featuredOnHome: true }))).toEqual(["Ouvrir l'événement", "Revenir publié", "Retirer de l'accueil"]);
    expect(labels(adminEvent({ status: "completed" }))).toEqual(["Ouvrir l'événement", "Rouvrir", "Mettre en avant"]);
    expect(labels(adminEvent({ status: "draft", isPublic: false, visibility: "private" }))).toEqual(["Ouvrir l'événement", "Configurer l'accès privé"]);
    expect(labels(adminEvent({ isPublic: false, visibility: "private", hasPrivateAccessPassword: true }))).toContain("Changer l'accès privé");
  });
});

describe("the events list (story 3.17)", () => {
  it("shows one next step and a menu per event, the title opening its hub", () => {
    const html = render(
      <EventList
        onConfigurePrivateAccess={noop}
        onConfigureRecap={noop}
        onFeature={noop}
        onTransition={noop}
        state={{ kind: "ready", events: [adminEvent(), adminEvent({ id: "e2", title: "ArchiLAN 3", status: "completed", hasRecap: true })] }}
      />,
    );

    // The table and the mobile cards both render each event.
    expect(html.match(/href="\/admin\/evenements\/e1"/g)).toHaveLength(2);
    expect(html.match(/>Démarrer</g)).toHaveLength(2);
    expect(html.match(/aria-haspopup="menu"/g)).toHaveLength(4);
    // A completed event with its recap has nothing left to put forward.
    expect(html).not.toContain("Attacher le récap");
    // The sections moved to the hub.
    for (const gone of ["Modifier", "Pelles", "Partenaires", "Sondages", "Présences", "Config. accès", ">Run<"]) {
      expect(html).not.toContain(gone);
    }
  });
});

describe("the event hub (story 3.17)", () => {
  it("groups every section of the event in the order of its life", () => {
    const sections = hubSections({ event: adminEvent(), onConfigurePrivateAccess: noop, onConfigureRecap: noop, onFeature: noop });

    expect(sections.map((section) => section.title)).toEqual(["Préparation", "Joueurs", "Jour J", "Après"]);
    expect(sections.flatMap((section) => section.entries.map((entry) => entry.label))).toEqual([
      "Informations",
      "Jeux",
      "Partenaires",
      "Accès privé",
      "Inscriptions",
      "Présences",
      "Sondages",
      "Pelles",
      "Run et sessions",
      "Récap / VOD",
      "Mettre en avant",
    ]);
  });

  it("says where each section stands", () => {
    const html = render(<AdminEventHubView event={adminEvent({ isAtCapacity: true, confirmedRegistrations: 40 })} onConfigurePrivateAccess={noop} onConfigureRecap={noop} onFeature={noop} onTransition={noop} />);

    expect(html).toContain("Sélection activée");
    expect(html).toContain("40/40 inscrits - complet");
    expect(html).toContain("Événement public");
    expect(html).toContain("Une fois l&#x27;événement terminé");
    expect(html).toContain('href="/admin/evenements/e1/session"');
    expect(html).toContain(">Dépublier<");
    expect(html).toContain(">Démarrer<");
  });

  it("points to what is missing instead of a dead end", () => {
    const html = render(
      <AdminEventHubView
        event={adminEvent({ status: "draft", gameSelectionEnabled: false })}
        onConfigurePrivateAccess={noop}
        onConfigureRecap={noop}
        onFeature={noop}
        onTransition={noop}
      />,
    );

    expect(html).toContain("Activer d&#x27;abord la sélection de jeux");
    expect(html).not.toContain('href="/admin/evenements/e1/session"');
    expect(html).toContain("Publier d&#x27;abord l&#x27;événement");
  });

  it("offers the recap once the event is over", () => {
    const sections = hubSections({ event: adminEvent({ status: "completed", hasRecap: true }), onConfigurePrivateAccess: noop, onConfigureRecap: noop, onFeature: noop });
    const recap = sections[3].entries[0];

    expect(recap.kind).toBe("button");
    expect(recap.state).toBe("Récap attaché");
  });
});

describe("the sections of an event (story 3.17)", () => {
  it("marks the current section, its sub-pages included", () => {
    expect(isCurrentSection("/admin/evenements/e1", "/admin/evenements/e1", "e1")).toBe(true);
    expect(isCurrentSection("/admin/evenements/e1", "/admin/evenements/e1/jeux", "e1")).toBe(false);
    expect(isCurrentSection("/admin/evenements/e1/session", "/admin/evenements/e1/session/s9/slots/2", "e1")).toBe(true);
    expect(isCurrentSection("/admin/evenements/e1/sondages", "/admin/evenements/e1/session", "e1")).toBe(false);

    const html = renderToStaticMarkup(<AdminEventNavView event={adminEvent()} eventId="e1" pathname="/admin/evenements/e1/inscriptions/r1" />);
    expect(html.match(/aria-current="page"/g)).toHaveLength(1);
    expect(html).toMatch(/aria-current="page"[^>]*href="\/admin\/evenements\/e1\/inscriptions"/);
    expect(html).toContain('href="/admin/evenements"');
  });

  it("story 3.18: a bar across the top, with the event, its state and the sections as tabs", () => {
    const html = renderToStaticMarkup(<AdminEventNavView event={adminEvent({ featuredOnHome: true })} eventId="e1" pathname="/admin/evenements/e1/presences" />);

    expect(html).toContain("ArchiLAN 4");
    expect(html).toContain("Publié");
    expect(html).toContain("Accueil");
    expect(html).toContain("Clermont-Ferrand");
    // The site's tabs, each with its icon.
    expect(html).toContain("border-b-2 px-4 text-sm font-semibold");
    expect(html.match(/<a [^>]*href="\/admin\/evenements\/e1[^"]*"[^>]*><svg/g)).toHaveLength(9);
    expect(html).toMatch(/aria-current="page"[^>]*href="\/admin\/evenements\/e1\/presences"/);
    // No side column any more.
    expect(html).not.toContain("lg:grid-cols");
  });

  it("story 3.18: the bar holds its place while the event loads", () => {
    const html = renderToStaticMarkup(<AdminEventNavView event={null} eventId="e1" pathname="/admin/evenements/e1" />);

    expect(html).toContain("animate-pulse");
    expect(html).toMatch(/aria-current="page"[^>]*href="\/admin\/evenements\/e1"/);
  });
});

/** Story 3.17: the hub reads the admin event list; an unknown id is a 404, a non-admin is told so. */
describe("the hub page (story 3.17)", () => {
  function renderHub(eventId: string, result: AdminEventsResult): string {
    const client = new QueryClient();
    client.setQueryData(["admin-events"], result);
    return renderToStaticMarkup(
      <QueryClientProvider client={client}>
        <AdminEventHub eventId={eventId} />
      </QueryClientProvider>,
    );
  }

  it("shows the event it knows", () => {
    const html = renderHub("e1", { kind: "ready", events: [adminEvent()] });

    expect(html).toContain("Vue d&#x27;ensemble");
    // Story 3.18: the event bar carries the name; the overview does not repeat it.
    expect(html).not.toContain("ArchiLAN 4");
    expect(html.match(/<h1/g)).toHaveLength(1);
  });

  it("answers 404 for an unknown event", () => {
    expect(() => renderHub("nope", { kind: "ready", events: [adminEvent()] })).toThrow("NEXT_NOT_FOUND");
  });

  it("tells a non-admin the page is not for them", () => {
    const html = renderHub("e1", { kind: "denied", message: "Accès réservé aux admins ArchiLAN." });

    expect(html).toContain("Accès réservé aux admins ArchiLAN.");
    expect(html).not.toContain("Vue d");
  });
});
