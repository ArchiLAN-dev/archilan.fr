import type { ReactNode } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { fetchMediaPublish, isMediaPublish, regenerateMediaKey, type MediaPublish } from "./media-publish-api";
import { MediaPublishPanel } from "./media-publish-card";

const BASE = TEST_API_BASE_URL;
const KEY = "0123456789abcdef0123456789abcdef";

const access: Extract<MediaPublish, { available: true }> = {
  available: true,
  live: false,
  path: "p-0123456789abcdef0123",
  key: KEY,
  whipUrl: "https://media.lan.archilan.fr:8889/p-0123456789abcdef0123/whip",
  srtUrl: `srt://media.lan.archilan.fr:8890?streamid=publish:p-0123456789abcdef0123:archilan:${KEY}&pkt_size=1316`,
  opensAt: "2026-11-20T09:00:00+00:00",
  closesAt: "2026-11-22T17:00:00+00:00",
};

function render(node: ReactNode): string {
  return renderToStaticMarkup(<QueryClientProvider client={new QueryClient()}>{node}</QueryClientProvider>);
}

/** Story 47.7: the registrant's card to send their screen to the régie. */
describe("media publish card", () => {
  it("gives the WHIP server and hides the key until asked", () => {
    const html = render(<MediaPublishPanel access={access} registrationId="r1" />);
    expect(html).toContain("Diffuser pour la régie");
    expect(html).toContain(access.whipUrl);
    expect(html).not.toContain(KEY);
    expect(html).toContain("Afficher");
    expect(html).toContain("B-frames à 0");
    expect(html).toContain("En attente de ton flux.");
  });

  it("says when the régie receives the stream", () => {
    expect(render(<MediaPublishPanel access={{ ...access, live: true }} registrationId="r1" />)).toContain("La régie te reçoit.");
  });

  it("asks before giving a new key", () => {
    const html = render(<MediaPublishPanel access={access} registrationId="r1" />);
    expect(html).toContain("Régénérer ma clé");
    expect(html).not.toContain("Confirmer");
  });

  it("accepts both shapes of the API answer and nothing else", () => {
    expect(isMediaPublish(access)).toBe(true);
    expect(isMediaPublish({ available: false, live: false, reason: "outside_window", opensAt: "2026-11-20T09:00:00+00:00", closesAt: null })).toBe(true);
    expect(isMediaPublish({ ...access, key: null })).toBe(false);
    expect(isMediaPublish({ available: false, live: true, reason: "x", opensAt: null, closesAt: null })).toBe(false);
  });

  it("reads and renews the access", async () => {
    server.use(
      http.get(`${BASE}/registrations/r1/media-publish`, () => HttpResponse.json({ data: access, meta: {} })),
      http.post(`${BASE}/registrations/r1/media-publish/regenerate`, () => HttpResponse.json({ data: { ...access, key: "f".repeat(32) }, meta: {} })),
      http.get(`${BASE}/registrations/other/media-publish`, () => HttpResponse.json({ error: { code: "forbidden" } }, { status: 403 })),
    );

    expect(await fetchMediaPublish("r1")).toEqual(access);
    expect((await regenerateMediaKey("r1"))?.available).toBe(true);
    expect(await fetchMediaPublish("other")).toBeNull();
  });
});
