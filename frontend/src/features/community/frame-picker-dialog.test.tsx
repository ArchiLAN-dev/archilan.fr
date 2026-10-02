import { renderToStaticMarkup } from "react-dom/server";

import { AVATAR_FRAMES } from "./avatar-frames";
import { FRAME_CATEGORIES, FramePicker } from "./frame-picker-dialog";
import { CENTRED_FRAMING } from "./image-framing";

const AVATAR = { avatarUrl: "https://m.test/me.png", name: "Jean", framing: null };
const BANNER = { presetKey: "default", imageUrl: null, framing: CENTRED_FRAMING, overlay: 40 };
const noop = () => {};

function render(legendaryAllowed: boolean, current: string | null = "gold", saved: string | null = "gold"): string {
  return renderToStaticMarkup(
    <FramePicker avatar={AVATAR} banner={BANNER} current={current} legendaryAllowed={legendaryAllowed} onApply={noop} onCancel={noop} saved={saved} />,
  );
}

const buttons = (html: string) => html.match(/<button[^>]*>/g) ?? [];
const isDisabled = (button: string) => / disabled=""/.test(button);

/**
 * Story 30.46. The frame picker window: one card per frame (plus "Aucun"), grouped under plain headings, the
 * legendary ones locked for a non-admin, the preview on the frame being tried.
 */
describe("FramePicker", () => {
  test("offers every frame and Aucun, grouped under the four headings", () => {
    const html = render(true);

    for (const category of FRAME_CATEGORIES) expect(html).toContain(`>${category}`);
    const cards = buttons(html).filter((b) => b.includes("aria-pressed"));
    expect(cards).toHaveLength(AVATAR_FRAMES.length + 1);
  });

  test("a non-admin sees the legendary frames locked", () => {
    const html = render(false);

    const locked = buttons(html).filter((b) => isDisabled(b) && b.includes("aria-pressed"));
    expect(locked).toHaveLength(AVATAR_FRAMES.filter((f) => f.category === "Légendaires").length);
    expect(html).toContain("Feu (réservé aux admins)");
    expect(html).toContain("réservés aux admins pour l&#x27;instant");
  });

  test("an admin can pick them", () => {
    const html = render(true);

    expect(buttons(html).some((b) => isDisabled(b) && b.includes("aria-pressed"))).toBe(false);
    expect(html).not.toContain("réservé aux admins");
  });

  test("it opens on the draft's frame, shown in the preview", () => {
    const html = render(true, "glitch", "gold");

    expect(html).toMatch(/<button[^>]*aria-label="Glitch"[^>]*aria-pressed="true"|<button[^>]*aria-pressed="true"[^>]*aria-label="Glitch"/);
    expect(html).toContain("/avatar-frames/glitch-still.webp");
  });
});
