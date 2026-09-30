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
});
