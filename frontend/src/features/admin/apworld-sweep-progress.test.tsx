import { renderToStaticMarkup } from "react-dom/server";

import { ApworldSweepProgress } from "./apworld-sweep-progress";

/** Story 38.9: how far the rolling test has come on the image in use. */
describe("ApworldSweepProgress", () => {
  test("names the image in use and the share of the catalogue tested on it", () => {
    const html = renderToStaticMarkup(
      <ApworldSweepProgress progress={{ currentImage: "ghcr.io/archilan-dev/archipelago:0.16.1", testedOnCurrentImage: 48, total: 250 }} />,
    );

    expect(html).toContain("ghcr.io/archilan-dev/archipelago:0.16.1");
    expect(html).toContain("48");
    expect(html).toContain("250");
    expect(html).toContain("testés sur cette image");
  });

  test("says the catalogue is fully checked once every apworld is", () => {
    const html = renderToStaticMarkup(<ApworldSweepProgress progress={{ currentImage: "archipelago:latest", testedOnCurrentImage: 250, total: 250 }} />);

    expect(html).toContain("tout le catalogue");
  });

  test("says nothing when the runner does not tell which image runs", () => {
    expect(renderToStaticMarkup(<ApworldSweepProgress progress={null} />)).toBe("");
  });
});
