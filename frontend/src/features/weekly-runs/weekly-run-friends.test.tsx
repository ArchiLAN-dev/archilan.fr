import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import type { AuthUser } from "../auth/auth-context";
import type { WeeklyRunFriend } from "./weekly-runs-api";
import { WeeklyRunFriends, weeklyFriendStatus } from "./weekly-run-friends";

let mockUser: Partial<AuthUser> | null = null;
jest.mock("../auth/auth-context", () => ({
  useAuth: () => ({ user: mockUser, loading: false, setUser: () => undefined }),
}));

function friend(slug: string, extra: Partial<WeeklyRunFriend> = {}): WeeklyRunFriend {
  return { userId: `u-${slug}`, slug, displayName: slug.toUpperCase(), avatarUrl: null, status: "registered", completionTimeSeconds: null, isViewer: false, ...extra };
}

function render(rows: WeeklyRunFriend[]): string {
  const client = new QueryClient();
  client.setQueryData(["weekly-run-friends", "w1"], rows);
  return renderToStaticMarkup(
    <QueryClientProvider client={client}>
      <WeeklyRunFriends weeklyRunId="w1" />
    </QueryClientProvider>,
  );
}

describe("weekly run friends (story 43.8)", () => {
  beforeEach(() => {
    mockUser = { id: "me" };
  });

  it("gives the time of a goal, else where the friend is", () => {
    expect(weeklyFriendStatus(friend("a", { status: "goal", completionTimeSeconds: 3725 }))).toBe("01:02:05");
    expect(weeklyFriendStatus(friend("b", { status: "launched" }))).toBe("Lancée");
    expect(weeklyFriendStatus(friend("c"))).toBe("Inscrit");
  });

  it("lists the friends with the viewer marked", () => {
    const html = render([friend("fast", { status: "goal", completionTimeSeconds: 600 }), friend("me", { isViewer: true })]);

    expect(html).toContain("Tes amis cette semaine");
    expect(html).toContain("00:10:00");
    expect(html).toContain("(toi)");
  });

  it("shows nothing without a friend taking part, or to a visitor", () => {
    expect(render([friend("me", { isViewer: true })])).toBe("");
    mockUser = null;
    expect(render([friend("fast")])).toBe("");
  });
});
