import { renderToStaticMarkup } from "react-dom/server";

import { hubSectionOrder, reliveLinks } from "./hub-sections";
import { ReliveLinks } from "./hub-tools";
import { RegistrantChecklist } from "./registrant-checklist";
import { RegistrantHubView, type RegistrantHubEvent } from "./registrant-hub";
import type { ChecklistItem } from "./registrant-hub-api";

/** Story 53.6: before, during, after. */
describe("hubSectionOrder", () => {
  it("puts what matters now first", () => {
    expect(hubSectionOrder("before")).toEqual(["checklist", "practicalInfo", "tools", "links"]);
    expect(hubSectionOrder("during")).toEqual(["tools", "practicalInfo", "checklist", "links"]);
    expect(hubSectionOrder("after")).toEqual(["relive", "tools", "links"]);
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

function render(now: string, status: RegistrantHubEvent["status"] = "closed"): string {
  return renderToStaticMarkup(
    <RegistrantHubView
      checklist={items}
      event={{ ...event, status }}
      now={new Date(now)}
      practicalInfo={{ markdown: "## Wifi", updatedAt: null }}
      registration={{ registrationId: "r1", confirmed: true }}
      relive={<p>RELIVE</p>}
      tools={<p>TOOLS</p>}
    />,
  );
}

function order(html: string, markers: string[]): number[] {
  return markers.map((marker) => html.indexOf(marker));
}

describe("the space at each moment", () => {
  it("before: the checklist first", () => {
    const [checklist, info, tools] = order(render("2026-11-18T10:00:00Z"), ["Ce qu&#x27;il me reste à faire", "Infos pratiques", "TOOLS"]);
    expect(checklist).toBeGreaterThan(-1);
    expect(checklist).toBeLessThan(info);
    expect(info).toBeLessThan(tools);
  });

  it("during: the tools first, the checklist folded", () => {
    const html = render("2026-11-21T12:00:00Z");
    const [tools, info, checklist] = order(html, ["TOOLS", "Infos pratiques", "Ce qu&#x27;il me reste à faire"]);
    expect(tools).toBeLessThan(info);
    expect(info).toBeLessThan(checklist);
    expect(html).toContain("4 sur 5 étapes faites");
    expect(html).toContain("<details");
    expect(html).not.toContain("RELIVE");
  });

  it("after: living it again, without the checklist nor the practical info", () => {
    const html = render("2026-11-23T10:00:00Z", "completed");
    expect(html.indexOf("Revivre l&#x27;event")).toBeLessThan(html.indexOf("TOOLS"));
    expect(html).toContain("RELIVE");
    expect(html).not.toContain("Ce qu&#x27;il me reste à faire");
    expect(html).not.toContain("Infos pratiques");
  });
});
