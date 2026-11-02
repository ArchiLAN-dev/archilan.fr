import { renderToStaticMarkup } from "react-dom/server";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { groupLine } from "./admin-quest-weeks";
import { suggestedContribution } from "./admin-quests-api";
import { questStatsLine, questTermsError } from "./admin-quests-page";
import { CollectiveQuestRow, CommunityCollectiveQuestSection, shareLine, type CollectiveQuestProgress } from "./collective-quest";
import { fetchCommunityCollectiveQuest, type CommunityCollectiveQuest } from "./collective-quest-api";
import { WeeklyQuestsView, isWeeklyQuests, type WeeklyQuests } from "./weekly-quests";

const collective: CollectiveQuestProgress = {
  key: "together",
  label: "Tous ensemble",
  description: "",
  reward: 40,
  metric: "checks",
  unit: "checks",
  target: 500,
  total: 312,
  mine: 4,
  minContribution: 10,
  scope: null,
  gameSlug: null,
  duration: false,
  done: false,
  paid: false,
};

const gauge: CommunityCollectiveQuest = {
  title: "Tous ensemble",
  description: "",
  objective: "500 checks",
  reward: 40,
  target: 500,
  total: 312,
  unit: "checks",
  duration: false,
  reached: false,
  renewsAt: "2026-11-08T23:00:00+00:00",
  countedAt: "2026-11-02T10:00:00+00:00",
};

describe("collective quest (story 41.69)", () => {
  it("shows the community's count, the member's share and what is left to be paid", () => {
    const html = renderToStaticMarkup(<CollectiveQuestRow quest={collective} />);

    expect(html).toContain("Quête collective : Tous ensemble");
    expect(html).toContain("312 / 500 checks");
    expect(html).toContain('aria-valuenow="312"');
    expect(html).toContain("Dont 4 de toi. Il t&#x27;en faut 10 pour toucher la récompense.");
  });

  it("says when the share is done, about to be paid, or paid", () => {
    expect(shareLine({ ...collective, mine: 24 })).toBe("Dont 24 de toi : ta part est faite.");
    expect(shareLine({ ...collective, mine: 24, done: true })).toBe("Dont 24 de toi : récompense créditée dans quelques minutes.");
    expect(shareLine({ ...collective, mine: 24, done: true, paid: true })).toBe("Dont 24 de toi : récompense reçue.");
    expect(shareLine({ ...collective, duration: true, mine: 90, minContribution: 120 })).toBe("Dont 1 h 30 de toi. Il t'en faut 2 h pour toucher la récompense.");
  });

  it("sits apart from the week's quests, after the chest", () => {
    const quests: WeeklyQuests = { week: "2026-W45", renewsAt: "2026-11-08T23:00:00+00:00", quests: [], collective, chest: null, history: [] };
    expect(isWeeklyQuests(quests)).toBe(true);
    expect(isWeeklyQuests({ ...quests, collective: { ...collective, mine: "4" } })).toBe(false);

    const html = renderToStaticMarkup(<WeeklyQuestsView quests={quests} />);
    expect(html).toContain("Quête collective : Tous ensemble");
    expect(html).not.toContain("Pas de quête cette semaine.");
  });

  it("shows the public gauge with no member named", async () => {
    const html = renderToStaticMarkup(<CommunityCollectiveQuestSection quest={gauge} />);
    expect(html).toContain("Quête collective de la semaine");
    expect(html).toContain("Tous ensemble : 500 checks à nous tous.");
    expect(html).toContain("312 / 500 checks");
    expect(renderToStaticMarkup(<CommunityCollectiveQuestSection quest={{ ...gauge, total: 512, reached: true }} />)).toContain("Objectif atteint");

    server.use(http.get(`${TEST_API_BASE_URL}/community/collective-quest`, () => HttpResponse.json({ quest: gauge })));
    await expect(fetchCommunityCollectiveQuest()).resolves.toEqual(gauge);
    server.use(http.get(`${TEST_API_BASE_URL}/community/collective-quest`, () => HttpResponse.json({ quest: null })));
    await expect(fetchCommunityCollectiveQuest()).resolves.toBeNull();
  });

  it("checks a collective quest in the admin form before sending it", () => {
    const terms = { title: "Ensemble", description: "", reward: 40, objectives: [{ metric: "checks", target: 500 }], inDraw: false, drawWeight: 1, collective: true, minContribution: 10 };
    const refused = "Une quête collective a un seul objectif, sans palier, sur des goals, des checks, des parties, du temps de jeu, des items envoyés ou des primes.";

    expect(questTermsError(terms)).toBeNull();
    expect(questTermsError({ ...terms, minContribution: 0 })).toBe("La part minimale va de 1 à la cible de l'objectif.");
    expect(questTermsError({ ...terms, minContribution: 501 })).toBe("La part minimale va de 1 à la cible de l'objectif.");
    expect(questTermsError({ ...terms, objectives: [{ metric: "weeklies", target: 50 }] })).toBe(refused);
    expect(questTermsError({ ...terms, objectives: [...terms.objectives, { metric: "goals", target: 5 }] })).toBe(refused);
    expect(suggestedContribution(500)).toBe(10);
    expect(suggestedContribution(20)).toBe(1);
    expect(suggestedContribution(6000, true)).toBe(120);
  });

  it("tells the admin how the community did", () => {
    const served = { questId: "together", title: "Tous ensemble", reward: 40, origin: "pinned" as const, retired: false, drawnGame: null, collective: true, groupTarget: 500 };
    expect(groupLine({ ...served, groupTotal: null })).toBe("Collective : pas encore comptée");
    expect(groupLine({ ...served, groupTotal: 312 }, true)).toBe("Collective : 312 / 500, en cours");
    expect(groupLine({ ...served, groupTotal: 312 })).toBe("Collective : 312 / 500, pas atteinte");
    expect(groupLine({ ...served, groupTotal: 512 })).toBe("Collective : 512 / 500, réussie");
    expect(questStatsLine({ weeksServed: 2, lastWeek: "2026-W44", lastMembers: 12, lastTiers: [], pelles: 480 }, true)).toBe(
      "Servie 2 semaines · 12 contributeurs récompensés la dernière fois · 480 pelles versées",
    );
  });
});
