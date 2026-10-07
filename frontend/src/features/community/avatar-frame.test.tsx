import { existsSync } from "node:fs";
import { join } from "node:path";
import { renderToStaticMarkup } from "react-dom/server";

import { AvatarFrame } from "./avatar-frame";
import { AVATAR_FRAMES, getAvatarFrame } from "./avatar-frames";

/**
 * Story 30.46. The fire frame is real fire on video, laid over the avatar. The server (and reduced motion) only
 * ever ship its transparent still (story 30.47); the picker swatch keeps that still inside the swatch.
 */
describe("AvatarFrame - video frame", () => {
  test("the catalog offers the fire frame among the legendary ones", () => {
    const fire = getAvatarFrame("fire");
    expect(fire).toMatchObject({ label: "Feu", category: "Légendaires", variant: "video" });
    expect(fire?.video).toEqual({
      webm: "/avatar-frames/fire.webm",
      mp4: "/avatar-frames/fire.mp4",
      poster: "/avatar-frames/fire-poster.webp",
      still: "/avatar-frames/fire-still.webp",
    });
    expect(AVATAR_FRAMES.filter((f) => f.variant === "video").every((f) => f.video !== undefined)).toBe(true);
  });

  test("the video frames get their own category, the CSS effects keep theirs", () => {
    const videoKeys = AVATAR_FRAMES.filter((f) => f.variant === "video").map((f) => f.key);
    expect(videoKeys).toEqual(["fire", "electric", "spectral_fire", "lava", "runes", "cosmic", "glitch"]);
    expect(AVATAR_FRAMES.filter((f) => f.variant === "video").every((f) => f.category === "Légendaires")).toBe(true);
    expect(AVATAR_FRAMES.filter((f) => f.category === "Effets").map((f) => f.key)).toEqual(["holographic", "gold_shimmer", "spectral"]);
    // `spectral_fire` is a new frame next to the CSS `spectral` one, not a replacement.
    expect(getAvatarFrame("spectral")?.variant).toBe("spectral");
  });

  test("every video frame ships its four files in public/", () => {
    const missing = AVATAR_FRAMES.flatMap((f) => (f.video ? [f.video.webm, f.video.mp4, f.video.poster, f.video.still] : [])).filter(
      (url) => !existsSync(join(process.cwd(), "public", url)),
    );
    expect(missing).toEqual([]);
  });

  test("renders the transparent still on the server, never a video", () => {
    const html = renderToStaticMarkup(
      <AvatarFrame className="size-24" frameKey="fire">
        <span>photo</span>
      </AvatarFrame>,
    );

    expect(html).toContain("photo");
    expect(html).toMatch(/<img[^>]*src="\/avatar-frames\/fire-still.webp"/);
    // The still blends with nothing: safe under any parent (story 30.47).
    expect(html).not.toContain("mix-blend-mode");
    expect(html).toMatch(/<img[^>]*aria-hidden="true"/);
    expect(html).not.toContain("<video");
  });

  test("the picker swatch shows the still, never a video", () => {
    const html = renderToStaticMarkup(
      <AvatarFrame className="size-11" frameKey="fire" preview>
        <span>★</span>
      </AvatarFrame>,
    );

    expect(html).toContain("★");
    expect(html).toContain("/avatar-frames/fire-still.webp");
    expect(html).not.toContain("<video");
  });

  test("CSS frames stay overlay-free, preview or not", () => {
    for (const preview of [false, true]) {
      const html = renderToStaticMarkup(
        <AvatarFrame className="size-24" frameKey="gold" preview={preview}>
          <span>photo</span>
        </AvatarFrame>,
      );
      expect(html).not.toContain("<img");
      expect(html).not.toContain("<video");
    }
  });
});
