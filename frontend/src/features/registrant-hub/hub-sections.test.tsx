import { renderToStaticMarkup } from "react-dom/server";

import { hubMainSections, reliveLinks } from "./hub-sections";
import { ReliveLinks } from "./hub-tools";
import { RegistrantChecklist } from "./registrant-checklist";
import { RegistrantHubView, type RegistrantHubEvent } from "./registrant-hub";
import type { ChecklistItem } from "./registrant-hub-api";

/** Story 53.6, as a dashboard since story 53.10: the main column at each moment. */
describe("hubMainSections", () => {
  it("puts the game first, living it again afterwards", () => {
    expect(hubMainSections("before")).toEqual(["game", "participation", "practicalInfo"]);
    expect(hubMainSections("during")).toEqual(["game", "practicalInfo", "participation"]);
    expect(hubMainSections("after")).toEqual(["relive"]);
  });
});

describe("reliveLinks", () => {
  it("always gives the results, then only what exists", () => {
    expect(reliveLinks({ eventSlug: "archilan-4", recapSessionId: null, vodUrl: null, hasPhotos: false })).toEqual([
      { key: "results", label: "Les résultats", href: "/evenements/archilan-4/resultats" },
    ]);
    expect(reliveLinks({ eventSlug: "archilan-4", recapSessionId: "s1", vodUrl: "https://youtu.be/x", hasPhotos: true }).map((link) => link.href)).toEqual([
      "/evenements/archilan-4/resultats",
      "/parties/s1",
      "https://youtu.be/x",
      "/evenements/archilan-4#photos",
    ]);
    expect(reliveLinks({ eventSlug: "archilan-4", recapSessionId: null, vodUrl: "", hasPhotos: false })).toHaveLength(1);
  });

  it("opens the replay in a new tab", () => {
    const html = renderToStaticMarkup(<ReliveLinks links={reliveLinks({ eventSlug: "a", recapSessionId: null, vodUrl: "https://youtu.be/x", hasPhotos: false })} />);
    expect(html).toMatch(/href="https:\/\/youtu.be\/x"[^>]*target="_blank"/);
  });
});

const items: ChecklistItem[] = [
  { key: "confirm", state: "done", required: true },
  { key: "games", state: "done", required: true },
  { key: "yaml", state: "done", required: true },
  { key: "registration_questions", state: "done", required: true },
  { key: "payment", state: "todo", required: true },
];

describe("the checklist during the event", () => {
  it("folds behind its counter", () => {
    const html = renderToStaticMarkup(<RegistrantChecklist eventSlug="a" folded items={items} registrationId="r1" />);
    expect(html).toContain("4 sur 5 étapes faites");
    expect(html).toContain("<details");
    expect(html).toContain("Voir la liste");
  });

  it("stays open before", () => {
    expect(renderToStaticMarkup(<RegistrantChecklist eventSlug="a" items={items} registrationId="r1" />)).not.toContain("<details");
  });
});

const event: RegistrantHubEvent = {
  id: "e1",
  slug: "archilan-4",
  title: "ArchiLAN 4",
  status: "closed",
  startsAt: "2026-11-21T09:00:00Z",
  endsAt: "2026-11-22T17:00:00Z",
  location: "Lille",
};

const done: ChecklistItem[] = items.map((item) => ({ ...item, state: "done" }));

function render(now: string, status: RegistrantHubEvent["status"] = "closed", checklist: ChecklistItem[] = items): string {
  return renderToStaticMarkup(
    <RegistrantHubView
      checklist={checklist}
      event={{ ...event, status }}
      game={<p>GAME</p>}
      now={new Date(now)}
      participation={<p>PARTICIPATION</p>}
      practicalInfo={{ markdown: "## Wifi", updatedAt: null }}
      registration={{ registrationId: "r1", confirmed: true }}
      relive={<p>RELIVE</p>}
      surveys={<p>SURVEYS</p>}
      tools={<p>TOOLS</p>}
    />,
  );
}

function order(html: string, markers: string[]): number[] {
  return markers.map((marker) => html.indexOf(marker));
}

const TODO = "Ce qu&#x27;il me reste à faire";

/** Story 53.10: the banner, then the main column, then the tools. */
describe("the dashboard at each moment", () => {
  it("before: what is left to do on top, then the game and the practical info, then the tools", () => {
    const [todo, surveys, game, participation, info, tools] = order(render("2026-11-18T10:00:00Z"), [
      TODO,
      "SURVEYS",
      "GAME",
      "PARTICIPATION",
      "Infos pratiques",
      "TOOLS",
    ]);
    expect(todo).toBeGreaterThan(-1);
    expect(todo).toBeLessThan(surveys);
    expect(surveys).toBeLessThan(game);
    expect(game).toBeLessThan(participation);
    expect(participation).toBeLessThan(info);
    expect(info).toBeLessThan(tools);
  });

  it("before, all done: no banner block, the list stays at hand beside the tools", () => {
    const html = render("2026-11-18T10:00:00Z", "closed", done);
    expect(html).not.toContain(TODO);
    expect(html).toContain("Tu es prêt !");
    expect(html.indexOf("TOOLS")).toBeLessThan(html.indexOf("Tu es prêt !"));
  });

  it("links the game once, from its own card", () => {
    expect(render("2026-11-18T10:00:00Z")).not.toContain("/inscription/r1/session");
  });

  it("during: the game first, the checklist folded in the banner", () => {
    const html = render("2026-11-21T12:00:00Z");
    expect(html.indexOf(TODO)).toBeLessThan(html.indexOf("GAME"));
    expect(html).toContain("4 sur 5 étapes faites");
    expect(html).toContain("<details");
    expect(html).not.toContain("RELIVE");
  });

  it("after: living it again, without the checklist nor the practical info", () => {
    const html = render("2026-11-23T10:00:00Z", "completed");
    expect(html.indexOf("Revivre l&#x27;event")).toBeLessThan(html.indexOf("TOOLS"));
    expect(html).toContain("RELIVE");
    expect(html).not.toContain("GAME");
    expect(html).not.toContain("PARTICIPATION");
    expect(html).not.toContain(TODO);
    expect(html).not.toContain("Tu es prêt !");
    expect(html).not.toContain("Infos pratiques");
  });
});
