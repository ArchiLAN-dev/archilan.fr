import { getPublicPostBySlugFromApi, getPublicPosts } from "@/features/content/public-posts-api";
import { getHomeRecaps } from "@/features/home/home-api";
import type { PublicEvent } from "./event-types";
import { getPublicEvent, getPublicEvents } from "./public-events-api";

/**
 * Story 34.10: the API revalidates these tags when an event or a post is written. A read without its tag would
 * keep serving the old page for up to five minutes, whatever the API asks.
 */
describe("tags de cache des lectures publiques", () => {
  let fetchSpy: jest.SpyInstance;

  beforeEach(() => {
    fetchSpy = jest.spyOn(globalThis, "fetch").mockResolvedValue(new Response("{}", { status: 500 }));
  });

  afterEach(() => fetchSpy.mockRestore());

  function tagsOfLastCall(): unknown {
    const init: unknown = fetchSpy.mock.calls.at(-1)?.[1];
    return typeof init === "object" && init !== null && "next" in init ? init.next : null;
  }

  test.each([
    ["la liste des événements", () => getPublicEvents(), { revalidate: 300, tags: ["events"] }],
    ["un événement", () => getPublicEvent("fef2ae3e"), { revalidate: 300, tags: ["events", "event:fef2ae3e"] }],
    ["la liste des actualités", () => getPublicPosts(), { revalidate: 300, tags: ["posts"] }],
    ["une actualité", () => getPublicPostBySlugFromApi("retour-archilan-3"), { revalidate: 300, tags: ["posts", "post:retour-archilan-3"] }],
    [
      "les recaps de l'accueil",
      () => getHomeRecaps([{ id: "evt-3", title: "ArchiLAN #3" } as PublicEvent]),
      { revalidate: 300, tags: ["events", "event:evt-3"] },
    ],
  ])("%s", async (_, read, expected) => {
    await read();

    expect(tagsOfLastCall()).toEqual(expected);
  });
});
