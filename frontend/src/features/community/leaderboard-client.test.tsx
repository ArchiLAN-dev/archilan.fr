import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import type { AuthUser } from "../auth/auth-context";
import type { LeaderboardResponse } from "./community-api";
import { LeaderboardClient } from "./leaderboard-client";

let mockUser: Partial<AuthUser> | null = null;
let mockFriendsInUrl = false;
jest.mock("../auth/auth-context", () => ({
  useAuth: () => ({ user: mockUser, loading: false, setUser: () => undefined }),
}));
// The server snapshot never has the URL: stand in for the client one.
jest.mock("../../lib/use-location-param", () => ({
  useLocationParam: () => (mockFriendsInUrl ? "1" : null),
  replaceLocationParam: jest.fn(),
}));

const EMPTY: LeaderboardResponse = { data: [], meta: { axis: "goals", page: 1, total: 0 } };

function render(): string {
  const client = new QueryClient();
  client.setQueryData(["leaderboard", "goals", 20, null, true], EMPTY);
  return renderToStaticMarkup(
    <QueryClientProvider client={client}>
      <LeaderboardClient events={[]} initialData={EMPTY} initialDataFetchedAt={0} />
    </QueryClientProvider>,
  );
}

describe("leaderboard friends toggle (story 43.8)", () => {
  beforeEach(() => {
    mockUser = { id: "me" };
    mockFriendsInUrl = false;
  });

  it("offers « Mes amis » to a signed-in member, off by default", () => {
    const html = render();
    expect(html).toContain("Mes amis");
    expect(html).toContain('aria-pressed="false"');
  });

  it("reads the toggle from the URL", () => {
    mockFriendsInUrl = true;
    const html = render();
    expect(html).toContain('aria-pressed="true"');
    expect(html).toContain("Ni toi ni tes amis");
  });

  it("hides the toggle from a visitor", () => {
    mockUser = null;
    mockFriendsInUrl = true;
    expect(render()).not.toContain("Mes amis");
  });
});
