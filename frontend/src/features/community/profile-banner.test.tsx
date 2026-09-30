import { renderToStaticMarkup } from "react-dom/server";

import { ProfileBanner } from "./profile-banner";

/**
 * Story 30.40. A banner image covers the banner, under the same shade as the presets; a moving GIF gives way to
 * its first frame for a visitor who asks for less motion.
 */
describe("ProfileBanner", () => {
  test("a preset draws its gradient", () => {
    const html = renderToStaticMarkup(<ProfileBanner presetKey="neon" />);

    expect(html).not.toContain("<img");
  });

  test("an image covers the banner, shade kept", () => {
    const html = renderToStaticMarkup(<ProfileBanner imageUrl="https://m.test/b.png" presetKey="neon" />);

    expect(html).toMatch(/<img[^>]*src="https:\/\/m.test\/b.png"/);
    expect(html).toMatch(/<img[^>]*object-cover/);
    expect(html).not.toContain("<source");
  });

  test("a moving image offers its first frame to reduced motion", () => {
    const html = renderToStaticMarkup(
      <ProfileBanner imageStillUrl="https://m.test/b-still.png" imageUrl="https://m.test/b.gif" presetKey="neon" />,
    );

    expect(html).toMatch(/<source[^>]*media="\(prefers-reduced-motion: reduce\)"[^>]*srcSet="https:\/\/m.test\/b-still.png"|<source[^>]*srcSet="https:\/\/m.test\/b-still.png"[^>]*media="\(prefers-reduced-motion: reduce\)"/);
  });

  test("the preset lies over the image at the chosen intensity (story 30.41)", () => {
    const html = renderToStaticMarkup(<ProfileBanner imageUrl="https://m.test/b.gif" overlay={30} presetKey="neon" />);

    expect(html).toMatch(/<img[^>]*src="https:\/\/m.test\/b.gif"/);
    // The preset layer, after the image, at 30 % opacity.
    expect(html.indexOf("<img")).toBeLessThan(html.indexOf("opacity:0.3"));
  });

  test("at 0 % the image shows alone, no preset layer", () => {
    const html = renderToStaticMarkup(<ProfileBanner imageUrl="https://m.test/b.gif" overlay={0} presetKey="neon" />);

    expect(html).not.toContain("opacity:");
  });
});
