import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import type { PlayerProfile } from "./player-profile-api";
import { PlayerProfilePage } from "./player-profile-page";

jest.mock("next/navigation", () => ({
  usePathname: () => "/joueurs/masterkafey",
  useRouter: () => ({ push: () => undefined, refresh: () => undefined }),
}));

const profile: PlayerProfile = {
  slug: "masterkafey",
  displayName: "MasterKafey",
  joinedAt: "2026-01-01T00:00:00Z",
  avatarUrl: null,
  audience: "public",
  badges: { member: true, admin: true },
  level: { level: 16, xp: 5000, xpIntoLevel: 10, xpForNextLevel: 100 },
  achievements: [],
  achievementStats: { unlocked: 0, total: 0 },
  presence: { playing: false, sessionId: null, game: null },
  customization: null,
  stats: { runsParticipated: 15, goalCompletions: 15, totalChecksDone: 5855, totalItemsReceived: 4200, goalCompletionRate: 1 },
};

function render(): string {
  return renderToStaticMarkup(
    <QueryClientProvider client={new QueryClient()}>
      <PlayerProfilePage history={null} profile={profile} />
    </QueryClientProvider>,
  );
}

/**
 * On a 360px phone the identity card clipped its own content: the name, in large type and unbreakable,
 * set the min-content of the card's grid, whose implicit column then grew wider than the card - and the
 * stat tiles ("Objectifs", "Taux de complétion") were cut off on the right. Bounded columns and a name
 * allowed to wrap keep everything inside.
 */
describe("PlayerProfilePage", () => {
  test("the page and the identity card bound their column to the screen", () => {
    const html = render();

    expect(html).toMatch(/<article class="[^"]*\bgrid-cols-1\b[^"]*"/);
    expect(html).toMatch(/<div class="relative z-10 grid grid-cols-1 [^"]*"/);
  });

  test("a long name wraps instead of setting the card's width, and shrinks on a phone", () => {
    const html = render();

    expect(html).toMatch(/<h1 class="[^"]*\bmin-w-0\b[^"]*\[overflow-wrap:anywhere\][^"]*"/);
    expect(html).toMatch(/<h1 class="[^"]*\btext-2xl\b[^"]*\bsm:text-3xl\b[^"]*"/);
  });

  test("the four stat tiles are still there", () => {
    const html = render();

    for (const label of ["Runs", "Objectifs", "Checks", "Taux de complétion"]) {
      expect(html).toContain(label);
    }
  });
});
