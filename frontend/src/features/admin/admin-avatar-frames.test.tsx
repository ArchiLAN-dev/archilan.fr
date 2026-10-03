import { renderToStaticMarkup } from "react-dom/server";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { AdminAvatarFrameList, fetchAdminAvatarFrames, framePoster, uploadAvatarFrame, type AdminAvatarFrame } from "./admin-avatar-frames";

const BASE = TEST_API_BASE_URL;
const fire: AdminAvatarFrame = { key: "fire", label: "Feu", access: "admins", builtIn: true, retired: false, position: 0, video: null };
const comet: AdminAvatarFrame = {
  key: "comet",
  label: "Comète",
  access: "shop",
  builtIn: false,
  retired: true,
  position: 7,
  video: { webm: "https://m.test/c.webm", mp4: "https://m.test/c.mp4", poster: "https://m.test/c.webp", still: "https://m.test/s.webp" },
};

/** Story 41.10: the admin of the video frames. */
describe("admin avatar frames", () => {
  test("a built-in frame shows the code's poster, an uploaded one its own", () => {
    expect(framePoster(fire)).toBe("/avatar-frames/fire-poster.webp");
    expect(framePoster(comet)).toBe("https://m.test/c.webp");
  });

  test("the list shows access, origin and state", () => {
    const html = renderToStaticMarkup(<AdminAvatarFrameList frames={[fire, comet]} onAccess={() => Promise.resolve()} onRetire={() => Promise.resolve()} />);

    expect(html).toContain("fire · intégré");
    expect(html).toContain("comet · retiré");
    expect(html).toContain("Rétablir");
    expect(html).toContain("Retirer");
  });

  test("API: lists, and relays the reasons of a refused upload", async () => {
    server.use(http.get(`${BASE}/admin/avatar-frames`, () => HttpResponse.json({ frames: [fire] })));
    expect(await fetchAdminAvatarFrames()).toEqual([fire]);

    server.use(
      http.post(`${BASE}/admin/avatar-frames`, () =>
        HttpResponse.json({ error: { code: "avatar_frame_files_invalid", message: "Fichiers", details: { poster: ["L'aperçu doit mesurer 512 x 512 pixels."] } } }, { status: 422 }),
      ),
    );
    expect(await uploadAvatarFrame({ key: "comet", label: "Comète", access: "shop" }, {})).toBe("L'aperçu doit mesurer 512 x 512 pixels.");
  });
});
