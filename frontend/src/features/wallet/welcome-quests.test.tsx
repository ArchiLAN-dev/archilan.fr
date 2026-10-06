import { renderToStaticMarkup } from "react-dom/server";

import { pelleReasonLabel } from "./wallet-api";
import { WelcomeQuestsView, isWelcomeQuests, type WelcomeQuests } from "./welcome-quests";

const welcome: WelcomeQuests = {
  total: 75,
  steps: [
    { key: "discord", label: "Lier ton compte Discord", description: "Pour les annonces.", reward: 10, done: true, paid: true },
    { key: "check", label: "Faire ton premier check", description: "Un item trouvé.", reward: 10, done: true, paid: false },
    { key: "weekly", label: "Jouer ta première hebdo", description: "Une tentative.", reward: 15, done: false, paid: false },
    { key: "partner", label: "Jouer avec un autre membre", description: "Un check chacun.", reward: 15, done: false, paid: false },
    { key: "goal", label: "Atteindre ton premier goal", description: "Ton jeu terminé.", reward: 25, done: false, paid: false },
  ],
};

/** Story 41.25: the first steps of a newcomer on « Mon portefeuille ». */
describe("welcome quests", () => {
  test("the payload is checked", () => {
    expect(isWelcomeQuests(welcome)).toBe(true);
    expect(isWelcomeQuests({ total: 75, steps: [{ key: "discord" }] })).toBe(false);
    expect(isWelcomeQuests(null)).toBe(false);
  });

  test("each step says whether it is done, credited soon, or where to do it", () => {
    const html = renderToStaticMarkup(<WelcomeQuestsView welcome={welcome} />);

    expect(html).toContain("Premiers pas");
    expect(html).toContain("2 / 5 faits");
    expect(html).toContain("Crédité dans quelques minutes.");
    expect(html).toContain('href="/runs-hebdo"');
    expect(html).not.toContain('href="/compte/securite"');
    expect(html).toContain("+25");
  });

  test("the ledger names the reason", () => {
    expect(pelleReasonLabel("welcome_reward")).toBe("Premiers pas");
  });
});
