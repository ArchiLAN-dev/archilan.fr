import { renderToStaticMarkup } from "react-dom/server";
import { http, HttpResponse } from "msw";

import { server } from "@/tests/setup";
import { TEST_API_BASE_URL } from "@/tests/constants";
import { checklistLine, RegistrantChecklist } from "./registrant-checklist";
import { fetchRegistrantChecklist, type ChecklistItem } from "./registrant-hub-api";
import { RegistrantHubView } from "./registrant-hub";

const context = { eventSlug: "archilan-4", registrationId: "r1" };

/** Story 53.2: what the registrant still has to do. */
describe("checklistLine", () => {
  it("links each step to its screen", () => {
    const href = (item: ChecklistItem) => checklistLine(item, context)?.href;

    expect(href({ key: "confirm", state: "todo", required: true })).toBe("/evenements/archilan-4/inscription/r1/recap");
    expect(href({ key: "games", state: "todo", required: true })).toBe("/evenements/archilan-4/inscription/r1/jeux");
    expect(href({ key: "yaml", state: "todo", required: true, detail: { done: 1, total: 2, firstSlotId: "s2" } })).toBe(
      "/evenements/archilan-4/inscription/r1/slots/s2",
    );
    expect(href({ key: "registration_questions", state: "todo", required: true })).toBe("/evenements/archilan-4/inscription/r1/questions");
    expect(href({ key: "payment", state: "todo", required: true })).toBe("/evenements/archilan-4#billetterie");
    expect(href({ key: "discord", state: "optional", required: false })).toBe("/compte/securite");
    // Story 53.7: the surveys are in the hub's tools, no longer on the public page.
    expect(href({ key: "surveys", state: "optional", required: false })).toBe("/evenements/archilan-4/mon-event#outils");
  });

  it("words the progress and the counts", () => {
    expect(checklistLine({ key: "yaml", state: "todo", required: true, detail: { done: 1, total: 2 } }, context)?.hint).toBe("1 sur 2");
    expect(checklistLine({ key: "surveys", state: "optional", required: false, detail: { count: 2 } }, context)?.label).toBe(
      "2 sondages t'attendent",
    );
    expect(checklistLine({ key: "surveys", state: "optional", required: false, detail: { count: 1 } }, context)?.label).toBe(
      "Un sondage t'attend",
    );
  });

  it("explains a payment not seen yet", () => {
    expect(checklistLine({ key: "payment", state: "todo", required: true }, context)?.hint).toContain("HelloAsso");
    expect(checklistLine({ key: "payment", state: "done", required: true }, context)?.hint).toBeUndefined();
  });

  it("skips a key it does not know", () => {
    expect(checklistLine({ key: "teleport", state: "todo", required: true }, context)).toBeNull();
  });
});

describe("RegistrantChecklist", () => {
  it("counts the required steps and links what is left", () => {
    const html = renderToStaticMarkup(
      <RegistrantChecklist
        {...context}
        items={[
          { key: "confirm", state: "done", required: true },
          { key: "games", state: "todo", required: true },
          { key: "discord", state: "optional", required: false },
        ]}
      />,
    );

    expect(html).toContain("1 sur 2 étapes faites");
    expect(html).toContain('href="/evenements/archilan-4/inscription/r1/jeux"');
    expect(html).not.toContain('href="/evenements/archilan-4/inscription/r1/recap"');
    expect(html).not.toContain("<details");
  });

  it("folds the list once every required step is done", () => {
    const html = renderToStaticMarkup(
      <RegistrantChecklist
        {...context}
        items={[
          { key: "confirm", state: "done", required: true },
          { key: "presence", state: "optional", required: false },
        ]}
      />,
    );

    expect(html).toContain("Tu es prêt !");
    expect(html).toContain("<details");
  });

  it("shows in the hub once loaded", () => {
    const props = {
      event: { id: "e1", slug: "archilan-4", title: "ArchiLAN 4", status: "closed" as const, location: "" },
      now: new Date("2026-11-18T10:00:00Z"),
      registration: { registrationId: "r1", confirmed: false },
    };

    expect(renderToStaticMarkup(<RegistrantHubView {...props} />)).not.toContain("Ce qu&#x27;il me reste à faire");
    expect(
      renderToStaticMarkup(<RegistrantHubView {...props} checklist={[{ key: "confirm", state: "todo", required: true }]} />),
    ).toContain("Ce qu&#x27;il me reste à faire");
  });
});

describe("fetchRegistrantChecklist", () => {
  it("reads the phase and the lines", async () => {
    server.use(
      http.get(`${TEST_API_BASE_URL}/registrations/r1/checklist`, () =>
        HttpResponse.json({
          data: {
            phase: "before",
            items: [
              { key: "confirm", state: "done", required: true },
              { key: "yaml", state: "todo", required: true, detail: { done: 0, total: 1, firstSlotId: "s1" } },
            ],
          },
          meta: {},
        }),
      ),
    );

    await expect(fetchRegistrantChecklist("r1")).resolves.toEqual({
      phase: "before",
      items: [
        { key: "confirm", state: "done", required: true },
        { key: "yaml", state: "todo", required: true, detail: { done: 0, total: 1, firstSlotId: "s1" } },
      ],
    });
  });

  it("reads nothing when refused or malformed", async () => {
    server.use(http.get(`${TEST_API_BASE_URL}/registrations/r1/checklist`, () => HttpResponse.json({ error: {} }, { status: 403 })));
    await expect(fetchRegistrantChecklist("r1")).resolves.toBeNull();
    server.use(
      http.get(`${TEST_API_BASE_URL}/registrations/r1/checklist`, () =>
        HttpResponse.json({ data: { phase: "before", items: [{ key: "confirm", state: "maybe", required: true }] } }),
      ),
    );
    await expect(fetchRegistrantChecklist("r1")).resolves.toBeNull();
  });
});
