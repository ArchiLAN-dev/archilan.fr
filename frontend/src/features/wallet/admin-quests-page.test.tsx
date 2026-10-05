import { renderToStaticMarkup } from "react-dom/server";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { fetchAdminQuests, objectivesSummary, pinQuest, type AdminQuests } from "./admin-quests-api";
import { ComingWeekRow, CurrentWeek, AdminQuestWeeksView } from "./admin-quest-weeks";
import { AdminQuestTypesView, questTermsError } from "./admin-quests-page";
import { weekLabel } from "./admin-quests-shared";
import { AdminQuestsTabs } from "./admin-quests-tabs";

const metrics = [
  { key: "goals", label: "Goals atteints", unit: "goals", unitOne: "goal" },
  { key: "checks", label: "Checks faits", unit: "checks", unitOne: "check" },
  { key: "sessions", label: "Parties jouées", unit: "parties", unitOne: "partie" },
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
    expect(objectivesSummary([{ metric: "goals", target: 1 }], metrics)).toBe("1 goal");
  });

  test("a coming week is one line: its pinned quests and the places left to the draw", () => {
    const html = renderToStaticMarkup(<ComingWeekRow onPin={noop} onUnpin={noop} pending={false} perWeek={3} week={data.weeks[1]} />);

    expect(html).toContain("12 oct. - 18 oct.");
    expect(html).toContain("Spéciale LAN");
    expect(html).toContain('aria-label="Désépingler « Spéciale LAN »"');
    expect(html).toContain("+ 2 au tirage");

    const drawn = renderToStaticMarkup(<ComingWeekRow onPin={noop} onUnpin={noop} pending={false} perWeek={3} week={{ ...data.weeks[1], drawn: true }} />);
    expect(drawn).not.toContain("au tirage");
  });

  test("the current week shows each quest in full, with its origin and what it pays", () => {
    const html = renderToStaticMarkup(
      <CurrentWeek byId={new Map(data.quests.map((quest) => [quest.id, quest]))} metrics={metrics} onAdd={noop} onRemove={noop} onReplace={noop} pending={false} week={data.weeks[0]} />,
    );

    expect(html).toContain("Cette semaine");
    expect(html).toContain("Marathon");
    expect(html).toContain("50 checks et 2 parties");
    expect(html).toContain("Tirée");
    expect(html).toContain("Remplacer");
    expect(html).toContain("1 quête");
  });

  test("the weeks and the quest types live on two pages", () => {
    const onChange = async (error: string | null) => error;
    const weeks = renderToStaticMarkup(<AdminQuestWeeksView data={data} onChange={onChange} />);
    const types = renderToStaticMarkup(<AdminQuestTypesView data={data} onChange={onChange} />);

    expect(weeks).toContain("Quêtes par semaine");
    expect(weeks).toContain("À venir");
    expect(weeks).toContain("moins que le nombre par semaine");
    expect(weeks).not.toContain("Nouvelle quête");

    expect(types).toContain("Nouvelle quête");
    expect(types).toContain("50 checks et 2 parties");
    expect(types).toContain("Hors tirage");
    expect(types).not.toContain("Quêtes par semaine");
  });

  test("one page, two tabs: the one shown is marked current", () => {
    const html = renderToStaticMarkup(<AdminQuestsTabs pathname="/admin/quetes/types" />);

    expect(html).toContain('href="/admin/quetes/semaines"');
    expect(html.match(/aria-current="page"/g)).toHaveLength(1);
    expect(html).toMatch(/aria-current="page"[^>]*href="\/admin\/quetes\/types"|href="\/admin\/quetes\/types"[^>]*aria-current="page"/);
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
