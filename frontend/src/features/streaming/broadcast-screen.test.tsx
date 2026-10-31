import { renderToStaticMarkup } from "react-dom/server";
import { http, HttpResponse } from "msw";

import { BroadcastScreenLinks } from "@/features/admin/broadcast-screen-links";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import {
  BroadcastMosaic,
  BroadcastRotation,
  broadcastScreenUrl,
  mosaicColumns,
  mosaicGroup,
  parseBroadcastScreenParams,
  rotationSlots,
} from "./broadcast-screen";
import { fetchBroadcastStreams, type ParticipantStream } from "./participant-streams-api";

const BASE = TEST_API_BASE_URL;

function live(login: string, displayName: string | null = null): ParticipantStream {
  return { userId: `u-${login}`, slug: `slug-${login}`, displayName, twitchLogin: login, avatarUrl: null, live: true, viewerCount: 10 };
}

/** Story 47.2: the régie screen for OBS. */
describe("broadcast screen", () => {
  it("reads its settings with defaults and bounds", () => {
    expect(parseBroadcastScreenParams(new URLSearchParams("event=demo423"))).toEqual({ eventId: "demo423", mode: "mosaique", max: 4, seconds: 30, bg: null });
    expect(parseBroadcastScreenParams(new URLSearchParams("event=a/b&mode=rotation&max=20&duree=2&bg=00ff00"))).toEqual({
      eventId: null,
      mode: "rotation",
      max: 9,
      seconds: 5,
      bg: "00ff00",
    });
    expect(parseBroadcastScreenParams(new URLSearchParams("max=abc&duree=1.5")).max).toBe(4);
  });

  it("builds the OBS links", () => {
    expect(broadcastScreenUrl("https://archilan.fr", "demo423")).toBe("https://archilan.fr/o/streams?event=demo423");
    expect(broadcastScreenUrl("https://archilan.fr", "demo423", "rotation")).toBe("https://archilan.fr/o/streams?event=demo423&mode=rotation");
  });

  it("sizes the mosaic and rotates groups over the cap", () => {
    expect([1, 2, 3, 4, 5, 6, 7, 9].map(mosaicColumns)).toEqual([1, 2, 2, 2, 3, 3, 3, 3]);
    const items = ["a", "b", "c", "d", "e"];
    expect(mosaicGroup(items, 9, 3)).toEqual(items);
    expect(mosaicGroup(items, 2, 0)).toEqual(["a", "b"]);
    expect(mosaicGroup(items, 2, 2)).toEqual(["e"]);
    expect(mosaicGroup(items, 2, 3)).toEqual(["a", "b"]);
  });

  it("features one stream after the other, the next ones as thumbnails", () => {
    const items = ["a", "b", "c", "d", "e"];
    expect(rotationSlots([], 4, 0)).toEqual({ featured: null, thumbnails: [] });
    expect(rotationSlots(items, 4, 0)).toEqual({ featured: "a", thumbnails: ["b", "c", "d"] });
    expect(rotationSlots(items, 4, 3)).toEqual({ featured: "d", thumbnails: ["e", "a", "b"] });
    expect(rotationSlots(items, 2, 1)).toEqual({ featured: "b", thumbnails: ["c"] });
    expect(rotationSlots(items, 9, 0).thumbnails).toHaveLength(3);
  });

  it("reads the broadcast list, null on a failure", async () => {
    server.use(http.get(`${BASE}/events/e1/broadcast-streams`, () => HttpResponse.json({ data: [live("alice")], meta: [] })));
    expect(await fetchBroadcastStreams("e1")).toEqual([live("alice")]);

    server.use(http.get(`${BASE}/events/e2/broadcast-streams`, () => new HttpResponse(null, { status: 404 })));
    expect(await fetchBroadcastStreams("e2")).toBeNull();
  });

  it("shows each player's name, and nothing when nobody is live", () => {
    expect(renderToStaticMarkup(<BroadcastMosaic streams={[]} />)).toBe("");
    expect(renderToStaticMarkup(<BroadcastRotation featured={null} thumbnails={[]} />)).toBe("");

    const mosaic = renderToStaticMarkup(<BroadcastMosaic streams={[live("alice", "Alice"), live("bob")]} />);
    expect(mosaic).toContain("grid-cols-2");
    expect(mosaic).toContain(">Alice</figcaption>");
    expect(mosaic).toContain(">slug-bob</figcaption>");

    const rotation = renderToStaticMarkup(<BroadcastRotation featured={live("alice", "Alice")} thumbnails={[live("bob", "Bob")]} />);
    expect(rotation).toContain("grid-cols-[3fr_1fr]");
    expect(rotation).toContain(">Bob</figcaption>");
  });

  it("gives the admin the links to paste in OBS", () => {
    const html = renderToStaticMarkup(<BroadcastScreenLinks eventId="demo423" />);
    expect(html).toContain("Écran régie");
    expect(html).toContain("/o/streams?event=demo423");
    expect(html).toContain("/o/streams?event=demo423&amp;mode=rotation");
  });
});
