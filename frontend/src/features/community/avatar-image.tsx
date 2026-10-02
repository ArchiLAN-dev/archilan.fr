/** The image to show: the GIF only while hovered, and never under reduced motion (story 30.42). */
export function avatarSource(src: string, animatedSrc: string | null | undefined, hovering: boolean, reducedMotion: boolean): string {
  return hovering && !reducedMotion && animatedSrc ? animatedSrc : src;
}
