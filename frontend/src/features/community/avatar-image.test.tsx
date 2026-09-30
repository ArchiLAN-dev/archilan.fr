import { renderToStaticMarkup } from "react-dom/server";

import { AvatarImage, avatarSource } from "./avatar-image";

/**
 * Story 30.42. Off the profile page, an admin's GIF avatar shows its first frame and moves only while hovered -
 * never for a visitor who asks for less motion.
 */
describe("avatarSource", () => {
  test("still at rest, the GIF while hovered", () => {
    expect(avatarSource("still.png", "anim.gif", false, false)).toBe("still.png");
    expect(avatarSource("still.png", "anim.gif", true, false)).toBe("anim.gif");
  });

  test("nothing moves under reduced motion or without a GIF", () => {
    expect(avatarSource("still.png", "anim.gif", true, true)).toBe("still.png");
    expect(avatarSource("photo.webp", null, true, false)).toBe("photo.webp");
    expect(avatarSource("photo.webp", undefined, true, false)).toBe("photo.webp");
  });
});

describe("AvatarImage", () => {
  test("renders the still image at rest", () => {
    const html = renderToStaticMarkup(<AvatarImage animatedSrc="anim.gif" className="size-10" src="still.png" />);

    expect(html).toMatch(/<img[^>]*src="still.png"/);
    expect(html).toContain('class="size-10"');
  });

  test("story 30.43: positions the image on the member's point", () => {
    const html = renderToStaticMarkup(<AvatarImage className="size-10" framing={{ x: 20, y: 70, zoom: 100 }} src="p.png" />);

    expect(html).toMatch(/(^|>)<img[^>]*class="size-10"/);
    expect(html).toContain("object-position:20% 70%");
  });

  test("story 30.43: a zoom clips the image in a box that takes the classes", () => {
    const html = renderToStaticMarkup(<AvatarImage className="size-10 rounded-full" framing={{ x: 20, y: 70, zoom: 150 }} src="p.png" />);

    expect(html).toMatch(/<span class="size-10 rounded-full block overflow-hidden"><img[^>]*class="size-full object-cover"/);
    expect(html).toContain("transform:scale(1.5)");
  });
});
