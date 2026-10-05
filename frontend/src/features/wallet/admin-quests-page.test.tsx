import { renderToStaticMarkup } from "react-dom/server";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { fetchAdminQuests, objectivesSummary, pinQuest, type AdminQuests } from "./admin-quests-api";
import { WeekCard, questTermsError, weekLabel } from "./admin-quests-page";

const metrics = [
  { key: "goals", label: "Goals atteints", unit: "goals" },
  { key: "checks", label: "Checks faits", unit: "checks" },
  { key: "sessions", label: "Parties jouées", unit: "parties" },
];

const data: AdminQuests = {
  questsPerWeek: 3,
  metrics,
  quests: [
    { id: "q1", title: "Marathon", description: "", reward: 60, objectives: [{ metric: "checks", target: 50 }, { metric: "sessions", target: 2 }], inDraw: true, retired: false, createdAt: "2026-10-05T10:00:00+00:00" },
    { id: "q2", title: "Spéciale LAN", description: "", reward: 100, objectives: [{ metric: "goals", target: 1 }], inDraw: false, retired: false, createdAt: "2026-10-05T10:00:00+00:00" },
  ],
  weeks: [
    { key: "2026-W41", startsAt: "2026-10-04T22:00:00+00:00", endsAt: "2026-10-11T22:00:00+00:00", current: true, drawn: true, quests: [{ questId: "q1", title: "Marathon", reward: 60, origin: "drawn", retired: false }] },
    { key: "2026-W42", startsAt: "2026-10-11T22:00:00+00:00", endsAt: "2026-10-18T22:00:00+00:00", current: false, drawn: false, quests: [{ questId: "q2", title: "Spéciale LAN", reward: 100, origin: "pinned", retired: false }] },
  ],
};

const noop = () => undefined;

/** Story 41.15: the admins write the weekly quests and plan the weeks. */
describe("admin weekly quests", () => {
  test("reads the overview and pins a quest in place of another", async () => {
    let body: unknown = null;
    server.use(
      http.get(`${TEST_API_BASE_URL}/admin/quests`, () => HttpResponse.json(data)),
      http.post(`${TEST_API_BASE_URL}/admin/quest-weeks/2026-W42/quests`, async ({ request }) => {
        body = await request.json();
        return new HttpResponse(null, { status: 204 });
      }),
    );

    expect(await fetchAdminQuests()).toEqual(data);
    expect(await pinQuest("2026-W42", "q1", "q2")).toBeNull();
    expect(body).toEqual({ questId: "q1", replaces: "q2" });
  });

  test("says a week by its days and a quest by its objectives", () => {
    expect(weekLabel(data.weeks[0])).toBe("5 oct. - 11 oct.");
    expect(objectivesSummary(data.quests[0].objectives, metrics)).toBe("50 checks et 2 parties");
  });

  test("a coming week tells how many quests its draw will add", () => {
    const html = renderToStaticMarkup(<WeekCard metrics={metrics} onChange={noop} perWeek={3} quests={data.quests} week={data.weeks[1]} />);

    expect(html).toContain("Spéciale LAN");
    expect(html).toContain('aria-label="Épinglée"');
    expect(html).toContain("Tirage le lundi : 2 quêtes au hasard.");
    expect(html).toContain("Marathon");

    const current = renderToStaticMarkup(<WeekCard metrics={metrics} onChange={noop} perWeek={3} quests={data.quests} week={data.weeks[0]} />);
    expect(current).toContain("En cours");
    expect(current).toContain('aria-label="Tirée au hasard"');
    expect(current).not.toContain("Tirage le lundi");
  });

  test("a quest the API would refuse is said before sending", () => {
    const terms = { title: "Marathon", description: "", reward: 60, objectives: [{ metric: "checks", target: 50 }], inDraw: true };

    expect(questTermsError(terms)).toBeNull();
    expect(questTermsError({ ...terms, title: "" })).toContain("titre");
    expect(questTermsError({ ...terms, reward: 1001 })).toContain("récompense");
    expect(questTermsError({ ...terms, objectives: [] })).toBe("Au moins un objectif.");
    expect(questTermsError({ ...terms, objectives: [{ metric: "checks", target: 0 }] })).toContain("cible");
  });
});
