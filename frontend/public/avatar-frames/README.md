# Avatar frame animations (Lottie)

Designer-made avatar frames are **Lottie JSON** files served from this folder.

## Add a frame

1. Grab an animation in **Lottie JSON** format (e.g. from https://lottiefiles.com — choose
   one whose centre is transparent so the avatar shows through).
2. Save it here as `<key>.json`, e.g. `fire.json`.
3. Point the frame at it in `src/features/community/avatar-frames.ts` by adding `lottie` to its config:

   ```ts
   { key: "fire", label: "Flammes", category: "Effets", variant: "fire", lottie: "/avatar-frames/fire.json" },
   ```

The public profile then renders the Lottie overlay; the editor swatches keep the lightweight
CSS/SVG preview (`variant`) for performance, and any frame with no `lottie` (or a failed load)
falls back to its `variant`. Motion is paused under `prefers-reduced-motion`.
