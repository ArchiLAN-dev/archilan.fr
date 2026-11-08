import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderToStaticMarkup } from "react-dom/server";

import { counterFact, counterIdOfFact, isCounterFact } from "./admin-achievement-counter-scope";
import { NamePicker, counterUsage } from "./admin-achievement-counters";
import type { AchievementCounter } from "./admin-achievement-counters-api";

function render(node: React.ReactNode): string {
  return renderToStaticMarkup(<QueryClientProvider client={new QueryClient()}>{node}</QueryClientProvider>);
}

describe("counter facts (story 30.57)", () => {
  it("builds and reads a counter fact", () => {
    expect(counterFact("c-1")).toBe("counter:c-1");
    expect(counterIdOfFact("counter:c-1")).toBe("c-1");
    expect(counterIdOfFact("counter:")).toBeNull();
    expect(isCounterFact("checks")).toBe(false);
  });
});

describe("NamePicker", () => {
  it("stays disabled until the game and what is counted are chosen", () => {
    const html = render(<NamePicker gameId="" kind="" onChange={() => {}} targets={[]} />);
    expect(html).toMatch(/<input[^>]*disabled=""/);
    expect(html).toContain("Choisis d&#x27;abord le jeu et ce qu&#x27;on compte");
  });

  it("opens the search once both are chosen and lists the picked names", () => {
    const html = render(<NamePicker gameId="g-1" kind="location" onChange={() => {}} targets={["Water Temple Morpha Heart"]} />);
    expect(html).not.toMatch(/<input[^>]*disabled=""/);
    expect(html).toContain("Checks visés");
    expect(html).toContain("Water Temple Morpha Heart");
    expect(html).toContain("Retirer Water Temple Morpha Heart");
  });

  it("names items for an item counter", () => {
    expect(render(<NamePicker gameId="g-1" kind="item" onChange={() => {}} targets={[]} />)).toContain("Items visés");
  });

  test("a counter read by a quest cannot go either (story 41.72)", () => {
    const counter: AchievementCounter = { id: "c1", label: "Morpha", gameId: "g-1", gameName: "OoT", kind: "location", targets: ["Morpha"], mode: "total", withOthersOnly: false, usedBy: 0 };
    expect(counterUsage(counter)).toBe("Aucun succès ni aucune quête ne le lit.");
    expect(counterUsage({ ...counter, usedByQuests: 1 })).toBe("1 quête le lit : retire-le de leurs conditions avant de le supprimer.");
    expect(counterUsage({ ...counter, usedBy: 2, usedByQuests: 3 })).toBe("2 succès et 3 quêtes le lisent : retire-le de leurs conditions avant de le supprimer.");
  });
});
