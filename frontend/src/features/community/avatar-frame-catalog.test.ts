import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { catalogFrameConfig, fetchAvatarFrameCatalog, frameLockReason } from "./avatar-frame-catalog";

const rights = (admin: boolean, member: boolean, owned: string[] = []) => ({ admin, member, owned });

/** Story 41.10: the admin catalog of video frames. */
describe("avatar frame catalog", () => {
  test("each access locks for its own reason", () => {
    const frame = { key: "comet", legendary: true, shop: false };

    expect(frameLockReason(frame, "free", rights(false, false))).toBeNull();
    expect(frameLockReason(frame, "members", rights(false, false))).toBe("Réservé aux adhérents");
    expect(frameLockReason(frame, "members", rights(false, true))).toBeNull();
    expect(frameLockReason(frame, "admins", rights(false, true))).toBe("Réservé aux admins pour l'instant");
    expect(frameLockReason(frame, "shop", rights(true, true))).toBe("En boutique");
    expect(frameLockReason(frame, "shop", rights(false, false, ["comet"]))).toBeNull();
  });

  test("without a catalog entry, the code's rule stays", () => {
    expect(frameLockReason({ key: "fire", legendary: true, shop: false }, undefined, rights(false, false))).toBe("Réservé aux admins pour l'instant");
    expect(frameLockReason({ key: "gold", legendary: false, shop: false }, undefined, rights(false, false))).toBeNull();
  });

  test("an uploaded frame is drawn as a video frame; a built-in one keeps the code's", () => {
    const video = { webm: "w", mp4: "m", poster: "p", still: "s" };

    expect(catalogFrameConfig({ key: "comet", label: "Comète", access: "free", builtIn: false, video })).toEqual({
      key: "comet",
      label: "Comète",
      category: "Légendaires",
      variant: "video",
      video,
    });
    expect(catalogFrameConfig({ key: "fire", label: "Feu", access: "admins", builtIn: true, video: null })).toBeNull();
  });

  test("reads the catalog, empty when the API is out of reach", async () => {
    server.use(http.get(`${TEST_API_BASE_URL}/avatar-frames`, () => HttpResponse.json({ frames: [{ key: "fire", label: "Feu", access: "admins", builtIn: true, video: null }] })));
    expect(await fetchAvatarFrameCatalog()).toHaveLength(1);

    server.use(http.get(`${TEST_API_BASE_URL}/avatar-frames`, () => new HttpResponse(null, { status: 500 })));
    expect(await fetchAvatarFrameCatalog()).toEqual([]);
  });

  test("a frame's shade is read when well formed, the catalog refused when not", async () => {
    const video = { webm: "w", mp4: "m", poster: "p", still: "s" };
    const serve = (shade: unknown) =>
      server.use(
        http.get(`${TEST_API_BASE_URL}/avatar-frames`, () =>
          HttpResponse.json({ frames: [{ key: "envy", label: "Envie", access: "free", builtIn: false, video: { ...video, shade } }] }),
        ),
      );

    serve({ webm: "sw", mp4: "sm" });
    expect((await fetchAvatarFrameCatalog())[0]?.video?.shade).toEqual({ webm: "sw", mp4: "sm" });
    serve(null);
    expect(await fetchAvatarFrameCatalog()).toHaveLength(1);
    serve({ webm: "sw" });
    expect(await fetchAvatarFrameCatalog()).toEqual([]);
  });
});
