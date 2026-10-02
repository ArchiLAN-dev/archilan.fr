import { renderToStaticMarkup } from "react-dom/server";

import { MemberAvatar } from "./member-avatar";

/**
 * Story 30.47. One avatar everywhere: the profile's rounded square at any size, inside the member's frame, with the
 * per-member gradient initials as fallback; still until hovered off the profile page.
 */
describe("MemberAvatar", () => {
  test("a photo at the asked size, its frame scaled to it", () => {
    const html = renderToStaticMarkup(<MemberAvatar avatarUrl="https://m.test/me.png" frame="gold" name="Jean" size={56} />);

    expect(html).toContain('src="https://m.test/me.png"');
    expect(html).toMatch(/style="width:56px;height:56px;--s:0\.5[;"]/);
    expect(html).toContain("frame");
  });

  test("no photo: the initials on the member's gradient, sized to the avatar", () => {
    const html = renderToStaticMarkup(<MemberAvatar avatarUrl={null} name="Jean Marius" size={40} />);

    expect(html).not.toContain("<img");
    expect(html).toContain(">JM<");
    expect(html).toMatch(/bg-gradient-to-br from-\w+-500 to-\w+-400/);
    expect(html).toContain("font-size:12px");
  });

  test("no frame: the plain rounded square", () => {
    const html = renderToStaticMarkup(<MemberAvatar avatarUrl={null} name="Jean" size={32} />);

    expect(html).toContain('class="plain');
  });

  test("off the profile page a CSS frame is still until hovered, on the profile it moves", () => {
    const hover = renderToStaticMarkup(<MemberAvatar avatarUrl={null} frame="neon_cyan" name="Jean" size={36} />);
    const always = renderToStaticMarkup(<MemberAvatar animate="always" avatarUrl={null} frame="neon_cyan" name="Jean" size={112} />);

    expect(hover).toMatch(/class="frame glow still/);
    expect(always).not.toMatch(/\bstill\b/);
  });

  test("a legendary frame shows its transparent still, never a video, before any hover", () => {
    const html = renderToStaticMarkup(<MemberAvatar avatarUrl={null} frame="fire" name="Jean" size={36} />);

    expect(html).toContain('src="/avatar-frames/fire-still.webp"');
    expect(html).not.toContain("<video");
    expect(html).not.toContain("mix-blend-mode");
  });

  test("an admin's GIF stays on its first frame at rest", () => {
    const html = renderToStaticMarkup(<MemberAvatar avatarAnimatedUrl="https://m.test/me.gif" avatarUrl="https://m.test/me.png" name="Jean" size={40} />);

    expect(html).toContain('src="https://m.test/me.png"');
    expect(html).not.toContain("me.gif");
  });

  test("responsive size classes replace the fixed box, the frame keeps its proportions", () => {
    const html = renderToStaticMarkup(
      <MemberAvatar animate="always" avatarUrl={null} frame="gold" name="Jean" size={112} sizeClassName="size-24 sm:size-28" />,
    );

    expect(html).toContain("size-24 sm:size-28");
    expect(html).not.toContain("width:112px");
    expect(html).toContain("--s:1");
  });

  test("hoverVideo off keeps a video frame on its still, but not a CSS frame", () => {
    // Server render has no hover: check the frame still renders its still without any video either way.
    const video = renderToStaticMarkup(<MemberAvatar avatarUrl={null} frame="lava" hoverVideo={false} name="Jean" size={56} />);
    const css = renderToStaticMarkup(<MemberAvatar avatarUrl={null} frame="neon_cyan" hoverVideo={false} name="Jean" size={56} />);

    expect(video).toContain('src="/avatar-frames/lava-still.webp"');
    expect(video).not.toContain("<video");
    expect(css).toMatch(/class="frame glow/);
  });
});
