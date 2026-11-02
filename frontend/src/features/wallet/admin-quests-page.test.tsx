import { renderToStaticMarkup } from "react-dom/server";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { announceQuestWeekOnDiscord, fetchAdminQuests, isAdminQuests, objectivesSummary, pinQuest, type AdminQuests, type QuestMetricOption } from "./admin-quests-api";
import { AdminQuestWeeksView, ComingWeekRow, CurrentWeek, DrawPanel, PastWeeks } from "./admin-quest-weeks";
import { AdminQuestTypesView, aimableScopes, chosenScope, questStatsLine, questTermsError, scopeValue, tiersOf, toggledFamily } from "./admin-quests-page";
import { weekLabel } from "./admin-quests-shared";
import { AdminQuestsTabs } from "./admin-quests-tabs";

const metrics: QuestMetricOption[] = [
  { key: "goals", label: "Goals atteints", unit: "goals", unitOne: "goal", scopable: true, scopes: ["game", "event", "platform", "recent"], help: "Aide.", duration: false },
  { key: "checks", label: "Checks faits", unit: "checks", unitOne: "check", scopable: true, scopes: ["game", "event", "platform", "recent"], help: "Aide.", duration: false },
  { key: "sessions", label: "Parties jouées", unit: "parties", unitOne: "partie", scopable: true, scopes: ["game", "event", "platform", "recent"], help: "Aide.", duration: false },
];

const data: AdminQuests = {
  questsPerWeek: 3,
  chestReward: 50,
  metrics,
  quests: [
    { id: "q1", title: "Marathon", description: "", reward: 60, objectives: [{ metric: "checks", target: 50 }, { metric: "sessions", target: 2 }], inDraw: true, drawWeight: 3, tiers: null, retired: false, createdAt: "2026-10-05T10:00:00+00:00", stats: { weeksServed: 3, lastWeek: "2026-W40", lastMembers: 12, lastTiers: [], pelles: 480 } },
    { id: "q2", title: "Spéciale LAN", description: "", reward: 100, objectives: [{ metric: "goals", target: 1 }], inDraw: false, drawWeight: 1, tiers: null, retired: false, createdAt: "2026-10-05T10:00:00+00:00", stats: { weeksServed: 0, lastWeek: null, lastMembers: 0, lastTiers: [], pelles: 0 } },
  ],
  weeks: [
    { key: "2026-W41", startsAt: "2026-10-04T22:00:00+00:00", endsAt: "2026-10-11T22:00:00+00:00", current: true, drawn: true, quests: [{ questId: "q1", title: "Marathon", reward: 60, origin: "drawn", retired: false, drawnGame: null }] },
    { key: "2026-W42", startsAt: "2026-10-11T22:00:00+00:00", endsAt: "2026-10-18T22:00:00+00:00", current: false, drawn: false, quests: [{ questId: "q2", title: "Spéciale LAN", reward: 100, origin: "pinned", retired: false, drawnGame: null }] },
  ],
  scopes: { games: [{ id: "hk", name: "Hollow Knight" }], events: [{ id: "lan3", title: "LAN #3" }], platforms: ["Nintendo 64", "Super Nintendo"] },
  pastWeeks: [
    {
      key: "2026-W40",
      startsAt: "2026-09-27T22:00:00+00:00",
      endsAt: "2026-10-04T22:00:00+00:00",
      quests: [{ questId: "q1", title: "Marathon", reward: 60, origin: "drawn", retired: false, drawnGame: null, members: 12, pelles: 720 }],
      chests: 4,
      pelles: 920,
    },
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

  test("a superlative aims at an event, never a game (story 41.65)", () => {
    const superlatives: QuestMetricOption = { key: "superlatives", label: "Superlatifs", unit: "superlatifs", unitOne: "superlatif", scopable: true, scopes: ["event"], help: "Aide.", duration: false };
    const scopes = { games: [{ id: "hk", name: "Hollow Knight" }], events: [{ id: "lan", title: "LAN #3" }], platforms: ["Switch"] };
    expect(aimableScopes(superlatives, scopes)).toEqual({ games: [], events: [{ id: "lan", title: "LAN #3" }], platforms: [], recent: false, drawn: false });
    expect(aimableScopes(metrics[0], scopes)).toEqual({ ...scopes, recent: true, drawn: false });
  });

  test("a game drawn each week, from one pool for the whole quest (story 41.67)", () => {
    const drawn = { metric: "goals", target: 2, scope: "drawn" as const, scopeId: "recent:30" };
    expect(objectivesSummary([drawn], metrics)).toBe("2 goals sur un jeu tiré parmi les nouveautés de 30 jours");
    expect(objectivesSummary([{ ...drawn, scopeId: "catalog" }], metrics)).toBe("2 goals sur un jeu tiré au sort dans le catalogue");
    expect(chosenScope("drawn:recent:30", { metric: "goals", target: 1 })).toEqual({ scope: "drawn", scopeId: "recent:30" });
    expect(scopeValue(drawn)).toBe("drawn:recent:30");
    expect(questTermsError({ title: "Mêlé", description: "", reward: 40, objectives: [drawn, { metric: "checks", target: 1, scope: "drawn", scopeId: "catalog" }], inDraw: true, drawWeight: 1 })).toBe(
      "Un seul jeu est tiré par semaine : choisis le même panier pour chaque objectif à jeu tiré.",
    );
  });

  test("a platform target picks families, and the new releases a window (story 41.66)", () => {
    const families = { metric: "goals", target: 3, scope: "platform" as const, scopeId: "Super Nintendo|Nintendo 64" };
    expect(objectivesSummary([families], metrics)).toBe("3 goals sur un jeu Nintendo 64 ou Super Nintendo");
    expect(objectivesSummary([{ metric: "sessions", target: 1, scope: "recent", scopeId: "30" }], metrics)).toBe("1 partie sur un jeu mis en ligne ces 30 derniers jours");

    // One option for every platform target; the families stay when the option is picked again.
    expect(scopeValue(families)).toBe("platform:");
    expect(chosenScope("platform:", families)).toEqual({ scope: "platform", scopeId: "Super Nintendo|Nintendo 64" });
    expect(chosenScope("platform:", { metric: "goals", target: 1 })).toEqual({ scope: "platform", scopeId: "" });
    expect(chosenScope("recent:30", families)).toEqual({ scope: "recent", scopeId: "30" });
    expect(chosenScope("", families)).toEqual({ scope: undefined, scopeId: undefined });

    expect(toggledFamily("Super Nintendo", "Nintendo 64", true)).toBe("Nintendo 64|Super Nintendo");
    expect(toggledFamily("Nintendo 64|Super Nintendo", "Nintendo 64", false)).toBe("Super Nintendo");
    expect(questTermsError({ title: "Semaine Nintendo", description: "", reward: 40, objectives: [{ ...families, scopeId: "" }], inDraw: true, drawWeight: 1 })).toBe("Coche au moins une plateforme.");
  });

  test("time played reads in hours, even for one hour (story 41.64)", () => {
    const time: QuestMetricOption = { key: "playMinutes", label: "Temps de jeu", unit: "de jeu", unitOne: "de jeu", scopable: true, scopes: ["game", "event", "platform", "recent"], help: "Saisi en heures.", duration: true };
    expect(objectivesSummary([{ metric: "playMinutes", target: 360 }], [...metrics, time])).toBe("6 h de jeu");
    expect(objectivesSummary([{ metric: "playMinutes", target: 90 }], [...metrics, time])).toBe("1 h 30 de jeu");
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
      <CurrentWeek byId={new Map(data.quests.map((quest) => [quest.id, quest]))} chestReward={50} metrics={metrics} scopes={data.scopes} onAdd={noop} onRemove={noop} onReplace={noop} pending={false} week={data.weeks[0]} />,
    );

    expect(html).toContain("Cette semaine");
    expect(html).toContain("Marathon");
    expect(html).toContain("50 checks et 2 parties");
    expect(html).toContain("Tirée");
    expect(html).toContain("Remplacer");
    expect(html).toContain("1 quête");
    expect(html).toContain("110");
    expect(html).toContain("coffre compris");
  });

  test("story 41.26: the current week offers « Annoncer sur Discord » only when the bot has a channel", () => {
    const byId = new Map(data.quests.map((quest) => [quest.id, quest]));
    const withDiscord = renderToStaticMarkup(
      <CurrentWeek byId={byId} chestReward={50} metrics={metrics} scopes={data.scopes} onAdd={noop} onAnnounce={noop} onRemove={noop} onReplace={noop} pending={false} week={data.weeks[0]} />,
    );
    const without = renderToStaticMarkup(
      <CurrentWeek byId={byId} chestReward={50} metrics={metrics} scopes={data.scopes} onAdd={noop} onRemove={noop} onReplace={noop} pending={false} week={data.weeks[0]} />,
    );

    expect(withDiscord).toContain("Annoncer sur Discord");
    expect(without).not.toContain("Annoncer sur Discord");
    expect(isAdminQuests({ ...data, discordEnabled: true })).toBe(true);
    expect(isAdminQuests({ ...data, discordEnabled: "yes" })).toBe(false);
  });

  test("story 41.26: announcing a week says what Discord did, or why it refused", async () => {
    server.use(
      http.post(`${TEST_API_BASE_URL}/admin/quest-weeks/2026-W41/announce-discord`, () => HttpResponse.json({ outcome: "updated" })),
      http.post(`${TEST_API_BASE_URL}/admin/quest-weeks/2026-W42/announce-discord`, () =>
        HttpResponse.json({ error: { code: "discord_not_configured", message: "Discord n'est pas configuré." } }, { status: 409 }),
      ),
    );

    expect(await announceQuestWeekOnDiscord("2026-W41")).toEqual({ outcome: "updated", error: null });
    expect(await announceQuestWeekOnDiscord("2026-W42")).toEqual({ outcome: null, error: "Discord n'est pas configuré." });
  });

  test("the draw panel gives the quests a week, the types in the draw and the next draw", () => {
    const save = async () => true;
    const html = renderToStaticMarkup(<DrawPanel chestReward={50} inDraw={3} nextDraw="2026-10-11T22:00:00+00:00" onSave={save} pending={false} perWeek={3} />);

    expect(html).toContain("Quêtes par semaine");
    expect(html).toContain("lundi 12 octobre");
    expect(html).toContain('href="/admin/quetes/types"');
    expect(html).not.toContain("moins de types dans le tirage");
    expect(html).not.toContain("Enregistrer");

    const short = renderToStaticMarkup(<DrawPanel chestReward={50} inDraw={1} nextDraw={null} onSave={save} pending={false} perWeek={3} />);
    expect(short).toContain("les semaines sans épinglée en auront 1");
  });

  test("past weeks show who did each quest, the chests and the pelles; each quest says how it did (story 41.16)", () => {
    const html = renderToStaticMarkup(<PastWeeks weeks={data.pastWeeks} />);
    expect(html).toContain("Semaines passées");
    expect(html).toContain("28 sept. - 4 oct.");
    expect(html).toContain("12<span class=\"sr-only\"> membres</span>");
    expect(html).toContain("4 coffres");
    expect(html).toContain("920");

    expect(questStatsLine(data.quests[0].stats)).toBe("Servie 3 semaines · 12 membres l'ont réussie la dernière fois · 480 pelles versées");
    expect(questStatsLine(data.quests[1].stats)).toBe("Jamais servie pour l'instant.");
    expect(questStatsLine({ weeksServed: 1, lastWeek: null, lastMembers: 0, lastTiers: [], pelles: 0 })).toContain("première semaine en cours");
  });

  test("a quest of one objective climbs tiers within what it pays (story 41.68)", () => {
    const objectives = [{ metric: "checks", target: 2 }];
    expect(tiersOf(objectives, 10, [])).toBeNull();
    expect(tiersOf(objectives, 10, [{ target: 4, reward: 20 }])).toEqual([{ target: 2, reward: 10 }, { target: 4, reward: 20 }]);
    expect(tiersOf([...objectives, { metric: "goals", target: 1 }], 10, [{ target: 4, reward: 20 }])).toBeNull();

    const terms = { title: "Toujours plus", description: "", reward: 10, objectives, inDraw: true, drawWeight: 1 };
    expect(questTermsError({ ...terms, tiers: [{ target: 2, reward: 10 }, { target: 2, reward: 20 }] })).toBe("Chaque palier vise plus haut que le précédent et paie au moins une pelle.");
    expect(questTermsError({ ...terms, tiers: [{ target: 2, reward: 10 }, { target: 4, reward: 995 }] })).toBe("Tous les paliers ensemble paient 1000 pelles au plus.");
    expect(questTermsError({ ...terms, tiers: [{ target: 2, reward: 10 }, { target: 4, reward: 20 }] })).toBeNull();

    expect(questStatsLine({ weeksServed: 2, lastWeek: "2026-W40", lastMembers: 3, lastTiers: [3, 2, 1], pelles: 120 })).toBe(
      "Servie 2 semaines · 3 membres l'ont réussie la dernière fois (palier 1 : 3, palier 2 : 2, palier 3 : 1) · 120 pelles versées",
    );
  });

  test("the chest is set in the draw panel", () => {
    const html = renderToStaticMarkup(<DrawPanel chestReward={0} inDraw={3} nextDraw={null} onSave={async () => true} pending={false} perWeek={3} />);
    expect(html).toContain("Coffre de la semaine");
    expect(html).toContain("Pas de coffre");
  });

  test("the weeks and the quest types live on two pages", () => {
    const onChange = async (error: string | null) => error;
    const weeks = renderToStaticMarkup(<AdminQuestWeeksView data={data} onChange={onChange} />);
    const types = renderToStaticMarkup(<AdminQuestTypesView data={data} onChange={onChange} />);

    expect(weeks).toContain("Quêtes par semaine");
    expect(weeks).toContain("À venir");
    expect(weeks).toContain("moins de types dans le tirage que de quêtes par semaine");
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

  test("an aimed objective names its game or event, and a weight above 1 shows (story 41.18)", () => {
    expect(objectivesSummary([{ metric: "goals", target: 1, scope: "game", scopeId: "hk" }, { metric: "checks", target: 20, scope: "event", scopeId: "lan3" }], metrics, data.scopes)).toBe(
      "1 goal sur Hollow Knight et 20 checks à LAN #3",
    );
    expect(objectivesSummary([{ metric: "goals", target: 1, scope: "game", scopeId: "gone" }], metrics, data.scopes)).toBe("1 goal sur un jeu retiré");

    const types = renderToStaticMarkup(<AdminQuestTypesView data={data} onChange={async (error: string | null) => error} />);
    expect(types).toContain("×3");
  });

  test("a quest the API would refuse is said before sending", () => {
    const terms = { title: "Marathon", description: "", reward: 60, objectives: [{ metric: "checks", target: 50 }], inDraw: true, drawWeight: 1 };

    expect(questTermsError(terms)).toBeNull();
    expect(questTermsError({ ...terms, title: "" })).toContain("titre");
    expect(questTermsError({ ...terms, reward: 1001 })).toContain("récompense");
    expect(questTermsError({ ...terms, objectives: [] })).toBe("Au moins un objectif.");
    expect(questTermsError({ ...terms, objectives: [{ metric: "checks", target: 0 }] })).toContain("cible");
    expect(questTermsError({ ...terms, objectives: [{ metric: "goals", target: 1 }, { metric: "goals", target: 1, scope: "game", scopeId: "hk" }] })).toBeNull();
    expect(questTermsError({ ...terms, objectives: [{ metric: "goals", target: 1 }, { metric: "goals", target: 2 }] })).toContain("identiques");
  });
});
