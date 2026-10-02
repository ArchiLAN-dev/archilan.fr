import { videoLayer } from "./avatar-frame-video";
import { getAvatarFrame, type AvatarFrameVideo } from "./avatar-frames";

function video(key: string): AvatarFrameVideo {
  const frame = getAvatarFrame(key);
  if (!frame?.video) throw new Error(`${key} is not a video frame`);
  return frame.video;
}

/**
 * Story 30.46. A browser never reloads a <video> whose <source> children change: trying one video frame after
 * another in the picker must give React a new element (a new key), or the previous effect keeps playing.
 */
describe("videoLayer", () => {
  test("each video frame gets its own key, so switching frames mounts a new video", () => {
    const fire = videoLayer(video("fire"), "layer", true);
    const electric = videoLayer(video("electric"), "layer", true);

    expect(fire.type).toBe("video");
    expect(fire.key).toBe("/avatar-frames/fire.webm");
    expect(electric.key).toBe("/avatar-frames/electric.webm");
    expect(fire.key).not.toBe(electric.key);
  });

  test("without motion it is the still image of the frame", () => {
    const still = videoLayer(video("lava"), "layer", false);

    expect(still.type).toBe("img");
    expect(still.props).toMatchObject({ src: "/avatar-frames/lava-poster.webp", alt: "" });
  });
});
