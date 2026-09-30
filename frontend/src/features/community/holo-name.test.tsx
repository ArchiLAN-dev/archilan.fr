import { renderToStaticMarkup } from "react-dom/server";

import { HoloName, isNameStyle } from "./holo-name";

/** Story 30.44. Gold for an admin, silver for a member, the plain name otherwise. */
describe("HoloName", () => {
  test("a plain name without a style", () => {
    expect(renderToStaticMarkup(<HoloName style={null}>Ada</HoloName>)).toBe("Ada");
    expect(renderToStaticMarkup(<HoloName className="font-bold" style={undefined}>Ada</HoloName>)).toBe('<span class="font-bold">Ada</span>');
  });

  test("gold and silver keep the text as text", () => {
    const gold = renderToStaticMarkup(<HoloName style="gold">Ada</HoloName>);
    expect(gold).toContain('data-name-style="gold"');
    expect(gold).toMatch(/>Ada<\/span>$/);

    expect(renderToStaticMarkup(<HoloName style="silver">Bob</HoloName>)).toContain('data-name-style="silver"');
  });

  test("a card name only moves on hover", () => {
    const html = renderToStaticMarkup(
      <HoloName onHover style="silver">
        Bob
      </HoloName>,
    );
    expect(html).toContain("onHover");
  });

  test("reads the style sent by the API", () => {
    expect(isNameStyle("gold")).toBe(true);
    expect(isNameStyle("bronze")).toBe(false);
    expect(isNameStyle(null)).toBe(false);
  });
});
