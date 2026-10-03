import { renderToStaticMarkup } from "react-dom/server";

import { ProfileBanner } from "./profile-banner";
import { bannerLockReason } from "./profile-banner-catalog";

const media = { image: "https://m.test/rain.webp", webm: "https://m.test/rain.webm", mp4: "https://m.test/rain.mp4" };

/** Story 41.11: banners of the admin catalog. */
describe("profile banner catalog", () => {
  test("an animated banner plays its video over its still image", () => {
    const html = renderToStaticMarkup(<ProfileBanner media={media} presetKey="rain" />);

    expect(html).toContain('src="https://m.test/rain.webp"');
    expect(html).toContain("<video");
    expect(html).toContain('src="https://m.test/rain.webm"');
    expect(html).toContain('poster="https://m.test/rain.webp"');
  });

  test("a still banner, or a swatch, shows the image alone", () => {
    expect(renderToStaticMarkup(<ProfileBanner media={{ ...media, webm: null, mp4: null }} presetKey="rain" />)).not.toContain("<video");
    expect(renderToStaticMarkup(<ProfileBanner compact media={media} presetKey="rain" />)).not.toContain("<video");
  });

  test("an unknown key outside a query client shows the default preset", () => {
    const html = renderToStaticMarkup(<ProfileBanner presetKey="rain" />);

    expect(html).not.toContain("<img");
    expect(html).toContain("--c1:#6366f1");
  });

  test("each banner is locked by its own access", () => {
    const rights = { admin: false, member: false, owned: ["nova"] };

    expect(bannerLockReason({ key: "sunset", shop: false }, undefined, rights)).toBeNull();
    expect(bannerLockReason({ key: "rain", shop: false }, "members", rights)).toBe("Réservée aux adhérents");
    expect(bannerLockReason({ key: "rain", shop: false }, "members", { ...rights, member: true })).toBeNull();
    expect(bannerLockReason({ key: "rain", shop: false }, "admins", rights)).toBe("Réservée aux admins");
    expect(bannerLockReason({ key: "rain", shop: false }, "shop", rights)).toBe("En boutique");
    expect(bannerLockReason({ key: "nova", shop: false }, "shop", rights)).toBeNull();
  });
});
