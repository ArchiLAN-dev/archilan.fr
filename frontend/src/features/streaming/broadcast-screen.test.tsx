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
import { broadcastStreamKey, fetchBroadcastStreams, isBroadcastStream, type BroadcastStream } from "./participant-streams-api";

const BASE = TEST_API_BASE_URL;

function live(login: string, displayName: string | null = null): BroadcastStream {
  return { kind: "twitch", userId: `u-${login}`, slug: `slug-${login}`, displayName, twitchLogin: login, avatarUrl: null, live: true, viewerCount: 10 };
}

function onLan(slug: string, displayName: string | null = null): BroadcastStream {
  return { kind: "local", userId: `u-${slug}`, slug, displayName, whepUrl: `https://media.lan.archilan.fr:8889/p-${slug}/whep` };
}

/** Story 47.2: the régie screen for OBS. */
describe("broadcast screen", () => {
  it("reads its settings with defaults and bounds", () => {
    expect(parseBroadcastScreenParams(new URLSearchParams("event=demo423"))).toEqual({ eventId: "demo423", mode: "mosaique", max: 4, seconds: 30, bg: null, regie: null });
    expect(parseBroadcastScreenParams(new URLSearchParams("event=a/b&mode=rotation&max=20&duree=2&bg=00ff00"))).toEqual({
      eventId: null,
      mode: "rotation",
      max: 9,
      seconds: 5,
      bg: "00ff00",
      regie: null,
    });
    expect(parseBroadcastScreenParams(new URLSearchParams("max=abc&duree=1.5")).max).toBe(4);
    // v0.66.1: the régie token, 32 hex, or nothing.
    expect(parseBroadcastScreenParams(new URLSearchParams(`event=e1&regie=${"a".repeat(32)}`)).regie).toBe("a".repeat(32));
    expect(parseBroadcastScreenParams(new URLSearchParams("event=e1&regie=nope")).regie).toBeNull();
  });

  it("builds the OBS links", () => {
    expect(broadcastScreenUrl("https://archilan.fr", "demo423")).toBe("https://archilan.fr/o/streams?event=demo423");
    expect(broadcastScreenUrl("https://archilan.fr", "demo423", "rotation")).toBe("https://archilan.fr/o/streams?event=demo423&mode=rotation");
    expect(broadcastScreenUrl("https://archilan.fr", "demo423", "mosaique", "b".repeat(32))).toBe(`https://archilan.fr/o/streams?event=demo423&regie=${"b".repeat(32)}`);
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

    // v0.66.1: the régie token goes along, for the LAN screens.
    let asked: string | null = null;
    server.use(
      http.get(`${BASE}/events/e3/broadcast-streams`, ({ request }) => {
        asked = new URL(request.url).searchParams.get("regie");
        return HttpResponse.json({ data: [onLan("carol")], meta: [] });
      }),
    );
    expect(await fetchBroadcastStreams("e3", "c".repeat(32))).toEqual([onLan("carol")]);
    expect(asked).toBe("c".repeat(32));
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

  it("plays a LAN screen in WebRTC next to a Twitch live, one key per player (story 47.8)", () => {
    const mosaic = renderToStaticMarkup(<BroadcastMosaic streams={[onLan("carol", "Carol"), live("bob", "Bob")]} />);
    expect(mosaic).toContain("<video");
    expect(mosaic).toContain(">Carol</figcaption>");
    expect(mosaic).toContain(">Bob</figcaption>");
    expect(broadcastStreamKey(onLan("carol"))).toBe("local-u-carol");
    expect(broadcastStreamKey(live("bob"))).toBe("twitch-bob");
  });

  it("accepts both kinds of entry and nothing else (story 47.8)", async () => {
    expect(isBroadcastStream(onLan("carol"))).toBe(true);
    expect(isBroadcastStream(live("bob"))).toBe(true);
    expect(isBroadcastStream({ kind: "local", userId: "u", slug: "s", displayName: null })).toBe(false);
    expect(isBroadcastStream({ ...live("bob"), kind: "youtube" })).toBe(false);

    server.use(http.get(`${BASE}/events/e3/broadcast-streams`, () => HttpResponse.json({ data: [onLan("carol"), live("bob")], meta: [] })));
    expect(await fetchBroadcastStreams("e3")).toEqual([onLan("carol"), live("bob")]);
  });

  it("gives the admin the links to paste in OBS", () => {
    const html = renderToStaticMarkup(<BroadcastScreenLinks eventId="demo423" />);
    expect(html).toContain("Écran régie");
    expect(html).toContain("/o/streams?event=demo423");
    expect(html).toContain("/o/streams?event=demo423&amp;mode=rotation");
  });
});
