import { hrefFor, messageFor } from "./notification-center";
import { contentFor } from "./notification-content";
import type { NotificationItem } from "./notifications-api";

jest.mock("../auth/auth-context", () => ({
  useAuth: () => ({ user: null, loading: false, setUser: () => undefined }),
}));

const alice = { slug: "alice", displayName: "Alice", avatarUrl: null };

function activity(data: Record<string, unknown>): NotificationItem {
  return { id: "n1", type: "friend_activity", createdAt: new Date().toISOString(), read: false, actor: alice, data, details: null };
}

/** Story 43.11b: what a starred friend did, in the bell. */
describe("friend activity notification", () => {
  test("says what the friend did and where", () => {
    expect(messageFor(activity({ kind: "registered", title: "LAN de printemps", eventId: "e1" }))).toBe("Alice s'inscrit à « LAN de printemps »");
    expect(messageFor(activity({ kind: "session_started", title: "LAN", others: 2 }))).toBe("Alice et 2 autres favoris lancent « LAN »");
    expect(messageFor(activity({ kind: "goal_reached", title: null }))).toBe("Alice a atteint son objectif");
    expect(contentFor(activity({ kind: "goal_reached", title: "Run" }), "").label).toBe("Favori");
  });

  test("leads to the event or the run the member may open, else to the friend", () => {
    expect(hrefFor(activity({ kind: "registered", eventId: "e1" }))).toBe("/evenements/e1");
    expect(hrefFor(activity({ kind: "goal_reached", runId: "r1" }))).toBe("/runs/r1");
    expect(hrefFor(activity({ kind: "session_started", eventId: null }))).toBe("/joueurs/alice");
  });
});
