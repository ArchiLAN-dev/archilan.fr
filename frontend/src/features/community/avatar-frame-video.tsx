"use client";

import { Fragment, useSyncExternalStore, type ReactElement } from "react";
import type { AvatarFrameVideo } from "./avatar-frames";

const REDUCED_MOTION = "(prefers-reduced-motion: reduce)";

function subscribe(onChange: () => void): () => void {
  const query = window.matchMedia(REDUCED_MOTION);
  query.addEventListener("change", onChange);
  return () => query.removeEventListener("change", onChange);
}

const motionAllowed = () => !window.matchMedia(REDUCED_MOTION).matches;
// The server never animates: the first client render matches it, then the animation takes over.
const motionAllowedOnServer = () => false;

/** Whether animations may play: false on the server and under "reduce motion", live-updated otherwise. */
export function useMotionAllowed(): boolean {
  return useSyncExternalStore(subscribe, motionAllowed, motionAllowedOnServer);
}

/**
 * The overlay of a video frame (stories 30.46, 30.47): the transparent still, or the looping video while `playing`
 * and motion is allowed. Purely decorative.
 */
export function AvatarFrameVideoLayer({ video, className, playing }: { video: AvatarFrameVideo; className: string; playing: boolean }) {
  const motion = useMotionAllowed();
  return videoLayer(video, className, motion && playing);
}

/**
 * The element of the overlay. The still is a transparent image that blends with nothing, so it is safe under any
 * parent (story 30.47). The video is light filmed on black and needs `screen`, set on the video only. `screen` can
 * only brighten: a frame whose shapes cover the photo (story 41.30) adds its shade, dark on white, laid in `multiply`
 * under the light and kept on the light's clock.
 */
export function videoLayer(video: AvatarFrameVideo, className: string, play: boolean): ReactElement {
  if (!play) {
    // eslint-disable-next-line @next/next/no-img-element -- decorative overlay; next/image would add a wrapper and lazy-load it
    return <img alt="" aria-hidden="true" className={className} src={video.still} />;
  }

  // Keyed by its file: a browser never reloads a <video> whose <source> children change, so switching from one
  // video frame to another (the picker's try-on) would keep playing the previous effect.
  const light = (
    <video
      // React sets `muted` as a property, never as the attribute, and some browsers (Safari) then refuse the autoplay
      // of a video mounted after load (on hover): start it explicitly, muted (story 30.47).
      ref={startMuted}
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
      style={{ mixBlendMode: "screen" }}
      tabIndex={-1}
    >
      <source src={video.webm} type="video/webm" />
      <source src={video.mp4} type="video/mp4" />
    </video>
  );
  if (!video.shade) return light;

  return (
    <Fragment key={video.webm}>
      <video
        ref={followLight}
        aria-hidden="true"
        autoPlay
        className={className}
        disablePictureInPicture
        key={video.shade.webm}
        loop
        muted
        playsInline
        preload="auto"
        style={{ mixBlendMode: "multiply" }}
        tabIndex={-1}
      >
        <source src={video.shade.webm} type="video/webm" />
        <source src={video.shade.mp4} type="video/mp4" />
      </video>
      {light}
    </Fragment>
  );
}

/** Drift the shade may take before it is put back on the light's clock: a seek every frame would stutter. */
const SHADE_DRIFT = 0.08;

/** The shade plays with the light, its next sibling: two videos never stay in step on their own (story 41.30). */
function followLight(shade: HTMLVideoElement | null): (() => void) | undefined {
  if (shade === null) return undefined;
  startMuted(shade);
  let frame = 0;
  const tick = () => {
    const light = shade.nextElementSibling;
    if (light instanceof HTMLVideoElement && Math.abs(shade.currentTime - light.currentTime) > SHADE_DRIFT) {
      shade.currentTime = light.currentTime;
    }
    frame = requestAnimationFrame(tick);
  };
  frame = requestAnimationFrame(tick);
  return () => cancelAnimationFrame(frame);
}

function startMuted(video: HTMLVideoElement | null): void {
  if (video === null) return;
  video.muted = true;
  // A refused play (hidden tab, power saving) just leaves the poster: nothing to report.
  video.play().catch(() => undefined);
}
