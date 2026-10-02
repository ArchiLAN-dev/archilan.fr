import { renderToStaticMarkup } from "react-dom/server";

import { ImageFramingEditor } from "./image-framing-dialog";

/** Story 30.43. The framing editor shows the image in its final shape, framed as saved. */
describe("ImageFramingEditor", () => {
  const noop = () => undefined;

  test("a rounded-square frame for the photo (story 30.47), with the saved framing and its zoom", () => {
    const html = renderToStaticMarkup(
      <ImageFramingEditor imageUrl="p.png" initial={{ x: 30, y: 60, zoom: 150 }} onCancel={noop} onConfirm={noop} shape="avatar" />,
    );

    expect(html).toContain("rounded-[16.7%]");
    expect(html).toContain("object-position:30% 60%");
    expect(html).toContain('value="150"');
    expect(html).toContain("Recentrer");
    expect(html).toContain("Valider");
  });

  test("a strip for the banner, with a word on narrow screens", () => {
    const html = renderToStaticMarkup(
      <ImageFramingEditor imageUrl="b.png" initial={{ x: 50, y: 50, zoom: 100 }} onCancel={noop} onConfirm={noop} shape="banner" />,
    );

    expect(html).toContain("aspect-[4/1]");
    expect(html).toContain("largeur de l&#x27;écran");
  });
});
