"use client";

import { useSyncExternalStore, type ReactElement } from "react";
import type { AvatarFrameVideo } from "./avatar-frames";

const REDUCED_MOTION = "(prefers-reduced-motion: reduce)";

function subscribe(onChange: () => void): () => void {
  const query = window.matchMedia(REDUCED_MOTION);
  query.addEventListener("change", onChange);
  return () => query.removeEventListener("change", onChange);
}

const motionAllowed = () => !window.matchMedia(REDUCED_MOTION).matches;
// The server never plays the video: the first client render matches it, then the video takes over.
const motionAllowedOnServer = () => false;

/**
 * The overlay of a video frame (story 30.46): the still poster on the server and under reduced motion, the looping
 * video once mounted otherwise. Purely decorative.
 */
export function AvatarFrameVideoLayer({ video, className }: { video: AvatarFrameVideo; className: string }) {
  const motion = useSyncExternalStore(subscribe, motionAllowed, motionAllowedOnServer);
  return videoLayer(video, className, motion);
}

/**
 * A picker swatch's overlay: the still, and the looping video only while `playing` (the swatch is hovered or
 * focused) and motion is allowed, so a page of swatches never plays them all at once.
 */
export function AvatarFrameSwatchLayer({ video, className, playing }: { video: AvatarFrameVideo; className: string; playing: boolean }) {
  const motion = useSyncExternalStore(subscribe, motionAllowed, motionAllowedOnServer);
  return videoLayer(video, className, motion && playing);
}

/** The element of the overlay: the still without motion, otherwise the looping video. */
export function videoLayer(video: AvatarFrameVideo, className: string, motion: boolean): ReactElement {
  if (!motion) {
    // eslint-disable-next-line @next/next/no-img-element -- decorative overlay; next/image would add a wrapper and lazy-load it
    return <img alt="" aria-hidden="true" className={className} src={video.poster} />;
  }

  // Keyed by its file: a browser never reloads a <video> whose <source> children change, so switching from one
  // video frame to another (the picker's try-on) would keep playing the previous effect.
  return (
    <video
      aria-hidden="true"
      autoPlay
      className={className}
      disablePictureInPicture
      key={video.webm}
      loop
      muted
      playsInline
      poster={video.poster}
      preload="auto"
      tabIndex={-1}
    >
      <source src={video.webm} type="video/webm" />
      <source src={video.mp4} type="video/mp4" />
    </video>
  );
}
