import { renderToStaticMarkup } from "react-dom/server";

import { PelleAmount } from "./pelle-amount";
import { afterBalance, PelleBalance } from "./pelle-balance";

/** Story 41.71: a colour per pelle, and an animation that plays once, on arrival, on hover and on a gain. */
describe("PelleAmount", () => {
  test.each([
    ["gold", "text-warning"],
    ["event", "text-pelle-silver"],
    ["bronze", "text-pelle-bronze"],
  ] as const)("gives %s pelles their colour", (kind, tone) => {
    expect(renderToStaticMarkup(<PelleAmount amount={5} kind={kind} />)).toContain(tone);
  });

  test("takes the colour of its context without a kind", () => {
    const html = renderToStaticMarkup(<PelleAmount amount={5} className="text-success" signed />);

    expect(html).toContain("text-success");
    expect(html).not.toContain("text-warning");
    expect(html).not.toContain("text-pelle");
  });
});

describe("PelleBalance", () => {
  test.each([
    ["gold", "gold"],
    ["event", "silver"],
    ["bronze", "bronze"],
  ] as const)("gives %s pelles their animation", (kind, effect) => {
    expect(renderToStaticMarkup(<PelleBalance amount={5} kind={kind} />)).toContain(`mark ${effect}`);
  });

  test("the animation is hidden from screen readers, the amount is not", () => {
    const html = renderToStaticMarkup(<PelleBalance amount={120} kind="gold" />);

    expect(html).toContain('aria-hidden="true"');
    expect(html).toContain("120");
    expect(html).toContain("pelles");
  });

  test("a gain plays the animation again", () => {
    expect(afterBalance({ seen: 10, plays: 2 }, 15)).toEqual({ seen: 15, plays: 3 });
  });

  test("a loss does not", () => {
    expect(afterBalance({ seen: 10, plays: 2 }, 4)).toEqual({ seen: 4, plays: 2 });
  });
});
