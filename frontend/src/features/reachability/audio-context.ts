"use client";

// A single, shared AudioContext primed on the first user gesture.
//
// Browser autoplay policy blocks an AudioContext that was never started during a user gesture. The
// goal-reached celebration can be triggered by a realtime event (no gesture), so a freshly-created
// context would stay suspended and play nothing - while the manual "show victory screen" button (a
// gesture) works. To make both paths behave the same, we create one shared context and resume it on
// the user's first interaction with the page; by goal time it is already running.

let sharedCtx: AudioContext | null = null;
let unlockInstalled = false;

const GESTURE_EVENTS = ["pointerdown", "keydown", "touchstart"] as const;

/**
 * The shared AudioContext, created lazily. Attempts to resume it (a no-op once already running).
 * Returns null when the Web Audio API is unavailable (SSR or unsupported browser).
 */
export function getSharedAudioContext(): AudioContext | null {
  if (typeof window === "undefined" || typeof window.AudioContext === "undefined") {
    return null;
  }

  if (null === sharedCtx) {
    try {
      sharedCtx = new AudioContext();
    } catch {
      return null;
    }
  }

  if ("suspended" === sharedCtx.state) {
    void sharedCtx.resume().catch(() => undefined);
  }

  return sharedCtx;
}

/**
 * Install one-time listeners that resume the shared AudioContext on the first user gesture, then
 * detach once it is running. Idempotent and safe to call at module load.
 */
export function primeAudioOnFirstGesture(): void {
  if (unlockInstalled || typeof window === "undefined") {
    return;
  }
  unlockInstalled = true;

  const unlock = (): void => {
    const ctx = getSharedAudioContext();
    if (null !== ctx && "suspended" !== ctx.state) {
      for (const evt of GESTURE_EVENTS) {
        window.removeEventListener(evt, unlock);
      }
    }
  };

  for (const evt of GESTURE_EVENTS) {
    window.addEventListener(evt, unlock, { passive: true });
  }
}
