import { renderToStaticMarkup } from "react-dom/server";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { AdminProfileBannerList, fetchAdminProfileBanners, uploadProfileBanner, type AdminProfileBanner } from "./admin-profile-banners";

const BASE = TEST_API_BASE_URL;
const preset: AdminProfileBanner = { key: "default", label: "Défaut", access: "free", builtIn: true, retired: false, position: 0, media: null };
const rain: AdminProfileBanner = {
  key: "rain",
  label: "Pluie",
  access: "shop",
  builtIn: false,
  retired: true,
  position: 10,
  media: { image: "https://m.test/rain.webp", webm: "https://m.test/rain.webm", mp4: "https://m.test/rain.mp4" },
};

/** Story 41.11: the admin of the profile banners. */
describe("admin profile banners", () => {
  test("the list shows origin, motion and state; the default banner stays open", () => {
    const html = renderToStaticMarkup(<AdminProfileBannerList banners={[preset, rain]} onAccess={() => Promise.resolve()} onRetire={() => Promise.resolve()} />);

    expect(html).toContain("default · intégrée");
    expect(html).toContain("rain · animée · retirée");
    expect(html).toContain("https://m.test/rain.webp");
    expect(html).toContain("Rétablir");
    expect(html).not.toContain(">Retirer<");
    expect(html).toMatch(/<select[^>]*disabled/);
  });

  test("API: lists, and relays the reasons of a refused upload", async () => {
    server.use(http.get(`${BASE}/admin/profile-banners`, () => HttpResponse.json({ banners: [preset, rain] })));
    expect(await fetchAdminProfileBanners()).toEqual([preset, rain]);

    server.use(
      http.post(`${BASE}/admin/profile-banners`, () =>
        HttpResponse.json(
          { error: { code: "profile_banner_files_invalid", message: "Fichiers", details: { mp4: ["Une bannière animée a besoin des deux vidéos, WebM et MP4."] } } },
          { status: 422 },
        ),
      ),
    );
    expect(await uploadProfileBanner({ key: "rain", label: "Pluie", access: "shop" }, {})).toBe("Une bannière animée a besoin des deux vidéos, WebM et MP4.");
  });
});
