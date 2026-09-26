import { renderToStaticMarkup } from "react-dom/server";

import { ApworldPreflightImage } from "./apworld-preflight-image";

const CURRENT = "ghcr.io/archilan-dev/archipelago:0.16.1";

/** Story 38.8: the game page says which Archipelago image the apworld was tested on. */
describe("ApworldPreflightImage", () => {
  test("names the image of a verdict produced on the image in use", () => {
    const html = renderToStaticMarkup(<ApworldPreflightImage image={CURRENT} onCurrentImage={true} runtimeImage={CURRENT} />);

    expect(html).toContain("Testé sur");
    expect(html).toContain(CURRENT);
    expect(html).not.toContain("plus l&#x27;image en service");
  });

  test("flags a verdict from an older image and names the one in use", () => {
    const html = renderToStaticMarkup(
      <ApworldPreflightImage image="ghcr.io/archilan-dev/archipelago:0.16.0" onCurrentImage={false} runtimeImage={CURRENT} />,
    );

    expect(html).toContain("archipelago:0.16.0");
    expect(html).toContain("Ce n&#x27;est plus l&#x27;image en service");
    expect(html).toContain(CURRENT);
  });

  test("says the image is unknown for a verdict older than the tracking", () => {
    const html = renderToStaticMarkup(<ApworldPreflightImage image={null} onCurrentImage={false} runtimeImage={CURRENT} />);

    expect(html).toContain("Image inconnue");
    expect(html).toContain("Ce n&#x27;est plus l&#x27;image en service");
  });

  test("a rebuilt tag shows the short ids, not the same reference twice (story 38.8 review)", () => {
    const html = renderToStaticMarkup(
      <ApworldPreflightImage
        image="archipelago:latest"
        imageId="sha256:aaaaaaaaaaaaaaaa"
        onCurrentImage={false}
        runtimeImage="archipelago:latest"
        runtimeImageId="sha256:bbbbbbbbbbbbbbbb"
      />,
    );

    expect(html).toContain("reconstruite");
    expect(html).toContain("aaaaaaaaaaaa");
    expect(html).toContain("bbbbbbbbbbbb");
  });

  test("claims nothing about freshness when the image in use is unknown", () => {
    const html = renderToStaticMarkup(<ApworldPreflightImage image={CURRENT} onCurrentImage={null} runtimeImage={null} />);

    expect(html).toContain(CURRENT);
    expect(html).not.toContain("image en service");
  });
});
