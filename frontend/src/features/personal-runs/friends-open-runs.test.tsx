import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import type { AuthUser } from "../auth/auth-context";
import type { FriendCard } from "@/features/community/community-friends-api";
import { FRIENDS_OPEN_RUNS_KEY, type FriendsOpenRun } from "./friends-open-runs-api";
import { FriendsOpenRuns, RunOpennessSetting, seatsLabel } from "./friends-open-runs";

const mockUser: Partial<AuthUser> | null = { id: "me" };
jest.mock("../auth/auth-context", () => ({
  useAuth: () => ({ user: mockUser, loading: false, setUser: () => undefined }),
}));
jest.mock("next/navigation", () => ({
  useRouter: () => ({ push: () => undefined }),
}));

function card(slug: string): FriendCard {
  return { userId: `u-${slug}`, slug, displayName: slug.charAt(0).toUpperCase() + slug.slice(1), avatarUrl: null };
}

function render(node: React.ReactNode, runs: FriendsOpenRun[] = []): string {
  const client = new QueryClient();
  client.setQueryData(FRIENDS_OPEN_RUNS_KEY, runs);
  return renderToStaticMarkup(<QueryClientProvider client={client}>{node}</QueryClientProvider>);
}

/** Story 43.14: a draft run opened to the owner's friends. */
describe("runs open to friends", () => {
  it("counts the seats, or who joined when there is no limit", () => {
    expect(seatsLabel({ seatsWanted: 4, joined: 2 })).toBe("2 / 4 places");
    expect(seatsLabel({ seatsWanted: null, joined: 0 })).toBe("Personne encore");
    expect(seatsLabel({ seatsWanted: null, joined: 1 })).toBe("1 joueur");
    expect(seatsLabel({ seatsWanted: null, joined: 3 })).toBe("3 joueurs");
  });

  it("lists the friends' open runs to join", () => {
    const html = render(<FriendsOpenRuns />, [
      { runId: "r1", title: "La giga async", seatsWanted: 4, joined: 1, createdAt: "2026-10-10T10:00:00Z", owner: card("alice") },
    ]);

    expect(html).toContain("Parties de tes amis");
    expect(html).toContain("Alice");
    expect(html).toContain("« La giga async »");
    expect(html).toContain("1 / 4 places");
    expect(html).toContain("Rejoindre");
  });

  it("shows nothing without an open run", () => {
    expect(render(<FriendsOpenRuns />)).toBe("");
  });

  it("offers the owner the seats only once open to friends", () => {
    const closed = render(<RunOpennessSetting openness="invite" runId="r1" seatsWanted={null} />);
    expect(closed).toContain("Sur invitation");
    expect(closed).toContain("Tous mes amis");
    expect(closed).not.toContain("Places pour tes amis");

    const open = render(<RunOpennessSetting openness="friends" runId="r1" seatsWanted={3} />);
    expect(open).toContain("Places pour tes amis");
    expect(open).toContain('value="3"');
  });
});
