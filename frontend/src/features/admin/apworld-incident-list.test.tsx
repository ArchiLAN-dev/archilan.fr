import { renderToStaticMarkup } from "react-dom/server";

import type { ApworldIncident } from "./admin-apworld-health-api";
import { ApworldIncidentList } from "./apworld-incident-list";

const noop = () => undefined;

const handlers = { onAcknowledge: noop, onResolve: noop, onIgnore: noop };

function incident(overrides: Partial<ApworldIncident> = {}): ApworldIncident {
  return {
    id: "incident-1",
    gameId: "game-1",
    gameName: "Crystal Project",
    apworldHash: "6d1ef721c1b08488d3f5a790984fbf5e6a956a1a7b2c3e66d280d1b1d744e136",
    type: "preflight_failed",
    status: "open",
    summary: "Fill.FillError: Could not access required locations",
    error: "Traceback (most recent call last):\nFill.FillError: Could not access required locations",
    openedAt: "2026-09-20T10:00:00+00:00",
    lastSeenAt: "2026-09-24T10:00:00+00:00",
    occurrences: 3,
    acknowledgedBy: null,
    acknowledgedAt: null,
    closedAt: null,
    closedBy: null,
    closedAutomatically: false,
    ...overrides,
  };
}

function render(incidents: ApworldIncident[], pendingId: string | null = null): string {
  return renderToStaticMarkup(
    <ApworldIncidentList emptyMessage="Aucun apworld en échec" incidents={incidents} pendingId={pendingId} {...handlers} />,
  );
}

/**
 * Story 38.3. The health page is where "un tel s'en occupe" becomes visible: each incident says what
 * broke, since when, and who holds it.
 */
describe("ApworldIncidentList", () => {
  test("an open incident says what broke, since when, and invites someone to take it", () => {
    const html = render([incident()]);

    expect(html).toContain("Crystal Project");
    expect(html).toContain('href="/admin/jeux/game-1"');
    expect(html).toContain("Test de génération en échec");
    expect(html).toContain("Ouvert");
    expect(html).toContain("Fill.FillError: Could not access required locations");
    expect(html).toContain("6d1ef721c1b0");
    expect(html).toContain("3 occurrences");
    expect(html).toContain("20/09/2026");
    expect(html).toContain("Personne ne s&#x27;en occupe");
    expect(html).toContain("Je m&#x27;en occupe");
    expect(html).toContain("Résoudre");
    expect(html).toContain("Ignorer");
  });

  test("the full error stays one click away, without leaving the page", () => {
    const html = render([incident()]);

    expect(html).toContain("<details");
    expect(html).toContain("Traceback (most recent call last):");
  });

  test("a taken incident names who holds it, and taking it over stays possible", () => {
    const html = render([
      incident({ status: "acknowledged", acknowledgedBy: { id: "admin-1", displayName: "Jean" }, acknowledgedAt: "2026-09-21T09:00:00+00:00" }),
    ]);

    expect(html).toContain("Pris en charge");
    expect(html).toContain("Jean s&#x27;en occupe");
    expect(html).toContain("Reprendre");
    expect(html).not.toContain("Je m&#x27;en occupe");
  });

  test("ignoring asks for confirmation, because it holds for this version", () => {
    const html = render([incident()]);

    expect(html).toContain('aria-haspopup="dialog"');
  });

  test("a closed incident tells how it ended and offers no action", () => {
    const html = render([
      incident({ id: "a", status: "resolved", closedAt: "2026-09-22T10:00:00+00:00", closedAutomatically: true }),
      incident({ id: "b", status: "ignored", closedAt: "2026-09-23T10:00:00+00:00", closedBy: { id: "admin-1", displayName: "Jean" } }),
    ]);

    expect(html).toContain("Résolu automatiquement");
    expect(html).toContain("Ignoré par Jean");
    expect(html).not.toContain("Je m&#x27;en occupe");
    expect(html).not.toContain("Résoudre</button>");
  });

  test("the incident being acted on has its buttons disabled", () => {
    const html = render([incident()], "incident-1");

    // The button holding the label, whatever icon sits before the text, carries `disabled`.
    expect(html).toMatch(/<button[^>]*disabled=""[^>]*>(?:(?!<\/button>)[\s\S])*Je m&#x27;en occupe/);
    expect(html).not.toMatch(/<button(?![^>]*disabled)[^>]*>(?:(?!<\/button>)[\s\S])*Résoudre/);
  });

  test("an empty list says so plainly", () => {
    expect(render([])).toContain("Aucun apworld en échec");
  });
});
