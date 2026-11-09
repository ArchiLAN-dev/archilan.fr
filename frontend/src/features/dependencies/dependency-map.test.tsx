import { renderToStaticMarkup } from "react-dom/server";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { bestGeneration, fetchDependencyMap, type DependencyGeneration, type DependencyMap } from "./dependency-map-api";
import { DependencyMapView } from "./dependency-map";

const risky: DependencyGeneration = {
  sessionId: "aaaaaaaa1111",
  status: "generated",
  playthrough: "read",
  score: 2,
  pairs: [{ waiting: "Alice", holding: "Bob", items: ["Hookshot", "Bow"], halfDays: ["sam. matin", "sam. après-midi"] }],
};
const calm: DependencyGeneration = { sessionId: "bbbbbbbb2222", status: "generated", playthrough: "read", score: 0, pairs: [] };
const map: DependencyMap = { declared: true, sphereLimit: 3, generations: [risky, calm, { ...calm, sessionId: "cccccccc3333", playthrough: "missing" }] };

/** Story 52.7: the dependency map of an event's generations. */
describe("carte des dépendances", () => {
  test("each generation shows its score and who waits for whom; the best is marked", () => {
    const html = renderToStaticMarkup(<DependencyMapView eventId="e1" map={map} />);

    expect(html).toContain("2 à risque");
    expect(html).toContain("0 à risque");
    expect(html).toContain("spoiler sans playthrough");
    expect(html).toContain("2 objets chez");
    expect(html).toContain("(Hookshot, Bow), pas là : sam. matin, sam. après-midi");
    expect(html.match(/Meilleure/g)).toHaveLength(1);
    expect(html).toContain('href="/admin/evenements/e1/session/bbbbbbbb2222"');
  });

  test("without declared windows, it says so and shows no risk", () => {
    const html = renderToStaticMarkup(<DependencyMapView eventId="e1" map={{ ...map, declared: false }} />);

    expect(html).toContain("déclaré de créneaux de présence");
    expect(html).not.toContain("objets chez");
  });

  test("the best needs two read generations and a single lowest score", () => {
    expect(bestGeneration([risky, calm])).toBe("bbbbbbbb2222");
    expect(bestGeneration([calm])).toBeNull();
    expect(bestGeneration([calm, { ...calm, sessionId: "x" }])).toBeNull();
  });

  test("API: reads the map with the sphere limit", async () => {
    let asked: string | null = null;
    server.use(
      http.get(`${TEST_API_BASE_URL}/admin/events/e1/dependency-map`, ({ request }) => {
        asked = new URL(request.url).searchParams.get("spheres");
        return HttpResponse.json({ data: map });
      }),
    );

    expect(await fetchDependencyMap("e1", 5)).toEqual(map);
    expect(asked).toBe("5");
  });
});
