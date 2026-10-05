import { renderToStaticMarkup } from "react-dom/server";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { WeeklyQuestsView, fetchMyQuests, type WeeklyQuests } from "./weekly-quests";
import { pelleReasonLabel } from "./wallet-api";

const quests: WeeklyQuests = {
  week: "2026-W40",
  renewsAt: "2026-10-04T22:00:00+00:00",
  quests: [
    { key: "reach_a_goal", label: "Atteindre un goal", description: "", reward: 40, done: true, paid: true, objectives: [{ metric: "goals", label: "Goals atteints", unit: "goals", target: 1, current: 1 }] },
    { key: "play_with_someone_new", label: "Jouer avec quelqu'un de nouveau", description: "", reward: 30, done: true, paid: false, objectives: [{ metric: "newPartners", label: "Nouveaux partenaires de jeu", unit: "nouveaux partenaires", target: 1, current: 1 }] },
    {
      key: "marathon",
      label: "Marathon",
      description: "Joue beaucoup cette semaine.",
      reward: 60,
      done: false,
      paid: false,
      objectives: [{ metric: "checks", label: "Checks faits", unit: "checks", target: 50, current: 12 }, { metric: "sessions", label: "Parties jouées", unit: "parties", target: 2, current: 3 }],
    },
  ],
};

/** Story 41.6: the quests of the week. */
describe("weekly quests", () => {
  test("shows each quest, done or not, and the renewal day", () => {
    const html = renderToStaticMarkup(<WeeklyQuestsView quests={quests} />);

    expect(html).toContain("Quêtes de la semaine");
    expect(html).toContain("Nouvelles quêtes le lundi 5 octobre");
    expect(html).toContain("+40");
    expect(html).toContain("créditée dans l&#x27;heure");
    expect(html.match(/créditée dans/g)).toHaveLength(1);
    expect(html).toContain("à faire");
  });

  test("each objective has its bar, capped at the target (story 41.15)", () => {
    const html = renderToStaticMarkup(<WeeklyQuestsView quests={quests} />);

    expect(html).toContain("Joue beaucoup cette semaine.");
    expect(html).toContain("12 / 50 checks");
    expect(html).toContain("width:24%");
    expect(html).toContain("2 / 2 parties");
    expect(html.match(/role="progressbar"/g)).toHaveLength(4);
    expect(renderToStaticMarkup(<WeeklyQuestsView quests={{ ...quests, quests: [] }} />)).toContain("Pas de quête cette semaine.");
  });

  test("reads the quests, and the reason has a label", async () => {
    server.use(http.get(`${TEST_API_BASE_URL}/me/quests`, () => HttpResponse.json(quests)));

    expect(await fetchMyQuests()).toEqual(quests);
    expect(pelleReasonLabel("quest_reward")).toBe("Quête de la semaine");
  });
});
