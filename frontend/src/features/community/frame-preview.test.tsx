import { renderToStaticMarkup } from "react-dom/server";

import { FramePreview, type FramePreviewBanner } from "./frame-preview";
import { CENTRED_FRAMING } from "./image-framing";

const BANNER: FramePreviewBanner = { presetKey: "default", imageUrl: "https://m.test/banner.png", framing: CENTRED_FRAMING, overlay: 40 };

/**
 * Story 30.46. Picking a frame tries it on the member's photo, over their own banner as on the profile page (the
 * screen-blended effects read differently on a light banner).
 */
describe("FramePreview", () => {
  test("shows the photo inside the frame being tried on, over the member's banner", () => {
    const html = renderToStaticMarkup(<FramePreview avatarUrl="https://m.test/me.png" banner={BANNER} frame="fire" framing={null} name="Jean" />);

    expect(html).toContain("https://m.test/me.png");
    expect(html).toContain("/avatar-frames/fire-poster.webp");
    expect(html).toContain("https://m.test/banner.png");
  });

  test("no frame shows the plain photo", () => {
    const html = renderToStaticMarkup(<FramePreview avatarUrl={null} banner={BANNER} frame={null} framing={null} name="Jean" />);

    expect(html).toContain("JE");
    expect(html).not.toContain("/avatar-frames/");
  });

  test("nothing in the preview sets a z-index, so the frame still blends with the banner", () => {
    const html = renderToStaticMarkup(<FramePreview avatarUrl={null} banner={BANNER} frame="fire" framing={null} name="Jean" />);

    expect(html).not.toMatch(/class="[^"]*\bz-\d+/);
  });
});
