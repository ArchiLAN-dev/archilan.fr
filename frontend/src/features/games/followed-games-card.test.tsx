import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { FOLLOWED_GAMES_KEY, FollowedGamesCard } from "./followed-games-card";
import { isFollowedGamesPayload, type FollowedGame } from "./game-follow-api";

function render(games: FollowedGame[]): string {
  const client = new QueryClient();
  client.setQueryData(FOLLOWED_GAMES_KEY, games);
  return renderToStaticMarkup(
    <QueryClientProvider client={client}>
      <FollowedGamesCard />
    </QueryClientProvider>,
  );
}

describe("followed games (story 38.23)", () => {
  it("lists each followed game with a link to its page and a way to stop", () => {
    const html = render([{ gameId: "g-1", gameName: "Celeste", gameSlug: "celeste" }]);

    expect(html).toContain("Jeux que je suis");
    expect(html).toContain('href="/jeux/celeste"');
    expect(html).toContain("Ne plus suivre");
  });

  it("says how to follow when the list is empty", () => {
    expect(render([])).toContain("Tu ne suis aucun jeu.");
  });

  it("reads only the expected shape", () => {
    expect(isFollowedGamesPayload({ data: [{ gameId: "g", gameName: "G", gameSlug: "g" }] })).toBe(true);
    expect(isFollowedGamesPayload({ data: [{ gameId: "g" }] })).toBe(false);
    expect(isFollowedGamesPayload({ data: null })).toBe(false);
  });
});
