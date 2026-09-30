import {
  CENTRED_FRAMING,
  dragFraming,
  framingStyle,
  isImageFraming,
  nudgeFraming,
  zoomFraming,
} from "./image-framing";

/**
 * Story 30.43. The part of an uploaded image a profile shows: a point aimed at (percent) and a zoom, applied in
 * CSS so a GIF keeps moving.
 */
describe("framingStyle", () => {
  test("nothing to apply when centred or absent", () => {
    expect(framingStyle(null)).toEqual({});
    expect(framingStyle(undefined)).toEqual({});
    expect(framingStyle(CENTRED_FRAMING)).toEqual({});
  });

  test("positions the image on the point and zooms around it", () => {
    expect(framingStyle({ x: 30, y: 80, zoom: 150 })).toEqual({
      objectPosition: "30% 80%",
      transform: "scale(1.5)",
      transformOrigin: "30% 80%",
    });
    expect(framingStyle({ x: 10, y: 50, zoom: 100 })).toEqual({ objectPosition: "10% 50%" });
  });
});

describe("dragFraming", () => {
  // A 200x100 image in a 100x100 round frame: covered at 100x100 height, so 100 px to pan horizontally.
  const frame = { width: 100, height: 100 };
  const image = { width: 200, height: 100 };

  test("dragging right shows more of the left side", () => {
    expect(dragFraming(CENTRED_FRAMING, 50, 0, frame, image)).toEqual({ x: 0, y: 50, zoom: 100 });
    expect(dragFraming(CENTRED_FRAMING, -25, 0, frame, image)).toEqual({ x: 75, y: 50, zoom: 100 });
  });

  test("an axis without room to pan does not move, until zoomed", () => {
    expect(dragFraming(CENTRED_FRAMING, 0, 40, frame, image)).toEqual(CENTRED_FRAMING);
    // At 200 %, the image is 400x200 in the frame: 100 px to pan vertically.
    expect(dragFraming({ x: 50, y: 50, zoom: 200 }, 0, 25, frame, image)).toEqual({ x: 50, y: 25, zoom: 200 });
  });

  test("stays within the image", () => {
    expect(dragFraming(CENTRED_FRAMING, 1000, 0, frame, image).x).toBe(0);
    expect(dragFraming(CENTRED_FRAMING, -1000, 0, frame, image).x).toBe(100);
  });
});

describe("nudgeFraming and zoomFraming", () => {
  test("the arrow keys move by 5 %, within bounds", () => {
    expect(nudgeFraming(CENTRED_FRAMING, "ArrowLeft")).toEqual({ x: 45, y: 50, zoom: 100 });
    expect(nudgeFraming(CENTRED_FRAMING, "ArrowDown")).toEqual({ x: 50, y: 55, zoom: 100 });
    expect(nudgeFraming({ x: 0, y: 100, zoom: 100 }, "ArrowLeft")).toEqual({ x: 0, y: 100, zoom: 100 });
    expect(nudgeFraming(CENTRED_FRAMING, "Enter")).toBeNull();
  });

  test("the zoom stays between 100 and 300 %", () => {
    expect(zoomFraming(CENTRED_FRAMING, 180).zoom).toBe(180);
    expect(zoomFraming(CENTRED_FRAMING, 40).zoom).toBe(100);
    expect(zoomFraming(CENTRED_FRAMING, 999).zoom).toBe(300);
  });
});

describe("isImageFraming", () => {
  test("reads the shape sent by the API", () => {
    expect(isImageFraming({ x: 1, y: 2, zoom: 150 })).toBe(true);
    expect(isImageFraming({ x: 1, y: 2 })).toBe(false);
    expect(isImageFraming(null)).toBe(false);
  });
});
