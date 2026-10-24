import { http, HttpResponse } from "msw";
import { server } from "@/tests/setup";
import { TEST_API_BASE_URL } from "@/tests/constants";
import { hrefFor, messageFor } from "@/features/community/notification-center";
import { contentFor } from "@/features/community/notification-content";
import type { NotificationItem } from "@/features/community/notifications-api";
import { fetchGameFollow, setGameFollow } from "./game-follow-api";

function item(data: Record<string, unknown>): NotificationItem {
  return { id: "n1", type: "game_apworld_updated", createdAt: new Date(2026, 9, 24, 10, 0).toISOString(), read: false, actor: null, data, details: null };
}

/** Story 38.22: follow a game, and hear of its new apworlds in the bell. */
describe("following a game", () => {
  it("reads and sets the follow", async () => {
    let method: string | null = null;
    server.use(
      http.get(`${TEST_API_BASE_URL}/games/g1/follow`, () => HttpResponse.json({ data: { following: false } })),
      http.put(`${TEST_API_BASE_URL}/games/g1/follow`, ({ request }) => {
        method = request.method;
        return HttpResponse.json({ data: { following: true } });
      }),
    );

    expect(await fetchGameFollow("g1")).toBe(false);
    expect(await setGameFollow("g1", true)).toBe(true);
    expect(method).toBe("PUT");
  });

  it("gives nothing when signed out", async () => {
    server.use(http.get(`${TEST_API_BASE_URL}/games/g1/follow`, () => HttpResponse.json({}, { status: 401 })));

    expect(await fetchGameFollow("g1")).toBeNull();
  });

  it("names the game and its version, and leads to its page", () => {
    const update = item({ gameId: "g1", gameName: "Hollow Knight", gameSlug: "hollow-knight", version: "v1.2.0", first: false });

    expect(messageFor(update)).toBe("Nouvel apworld pour Hollow Knight (v1.2.0)");
    expect(contentFor(update, "")).toMatchObject({ label: "Jeu suivi", detail: "Version v1.2.0" });
    expect(hrefFor(update)).toBe("/jeux/hollow-knight");
  });

  it("says a game became playable on its first apworld", () => {
    const first = item({ gameId: "g1", gameName: "Hollow Knight", gameSlug: "hollow-knight", version: null, first: true });

    expect(messageFor(first)).toBe("Hollow Knight est jouable sur le site");
    expect(contentFor(first, "").title).toEqual([{ text: "Hollow Knight", strong: true }, { text: " est jouable sur le site" }]);
  });
});
