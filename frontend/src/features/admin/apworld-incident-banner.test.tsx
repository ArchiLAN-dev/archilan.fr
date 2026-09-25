import { renderToStaticMarkup } from "react-dom/server";

import type { ApworldIncident } from "./admin-apworld-health-api";
import { ApworldIncidentBanner } from "./apworld-incident-banner";

function incident(overrides: Partial<ApworldIncident> = {}): ApworldIncident {
  return {
    id: "incident-1",
    gameId: "game-1",
    gameName: "Crystal Project",
    apworldHash: "6d1ef721c1b0",
    type: "preflight_failed",
    status: "open",
    summary: "Fill.FillError: Could not access required locations",
    error: "Fill.FillError: Could not access required locations",
    openedAt: "2026-09-20T10:00:00+00:00",
    lastSeenAt: "2026-09-24T10:00:00+00:00",
    occurrences: 1,
    acknowledgedBy: null,
    acknowledgedAt: null,
    closedAt: null,
    closedBy: null,
    closedAutomatically: false,
    ...overrides,
  };
}

/** Story 38.3 AC6: a game with an active incident says so on its own admin page. */
describe("ApworldIncidentBanner", () => {
  test("names the problem, who holds it, and leads to the health page", () => {
    const html = renderToStaticMarkup(<ApworldIncidentBanner incidents={[incident()]} />);

    expect(html).toContain('role="status"');
    expect(html).toContain("Test de génération en échec");
    expect(html).toContain("Fill.FillError: Could not access required locations");
    expect(html).toContain("Personne ne s&#x27;en occupe");
    expect(html).toContain('href="/admin/sante-apworlds"');
  });

  test("says who took it", () => {
    const html = renderToStaticMarkup(
      <ApworldIncidentBanner incidents={[incident({ status: "acknowledged", acknowledgedBy: { id: "a", displayName: "Jean" } })]} />,
    );

    expect(html).toContain("Jean s&#x27;en occupe");
  });

  test("renders nothing without an active incident", () => {
    expect(renderToStaticMarkup(<ApworldIncidentBanner incidents={[]} />)).toBe("");
  });
});
