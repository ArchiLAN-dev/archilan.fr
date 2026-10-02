"use client";

import { useSyncExternalStore } from "react";
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

  if (!motion) {
    // eslint-disable-next-line @next/next/no-img-element -- decorative overlay; next/image would add a wrapper and lazy-load it
    return <img alt="" aria-hidden="true" className={className} src={video.poster} />;
  }

  return (
    <video
      aria-hidden="true"
      autoPlay
      className={className}
      disablePictureInPicture
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
