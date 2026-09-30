/**
 * Profile images on the member's side (story 30.40): what the file pickers accept and say, and a readable
 * reason for each refusal. The API decides; this only mirrors its rules for the form.
 */

export type CustomImageSlot = "avatar" | "banner";

const STILL_TYPES = "image/jpeg,image/png,image/webp";

export function imageAccept(gifAllowed: boolean): string {
  return gifAllowed ? `${STILL_TYPES},image/gif` : STILL_TYPES;
}

export function imageFormatsHint(slot: CustomImageSlot, gifAllowed: boolean): string {
  if (slot === "avatar") {
    return gifAllowed ? "JPEG, PNG ou WebP (5 Mo max), ou GIF animé (10 Mo max)." : "JPEG, PNG ou WebP, 5 Mo max.";
  }
  return gifAllowed ? "JPEG, PNG, WebP ou GIF animé, 10 Mo max." : "JPEG, PNG ou WebP, 10 Mo max.";
}

const ERRORS: Record<string, string> = {
  image_gif_admin_only: "Les GIF sont réservés aux admins.",
  image_animation_unsupported: "Les images animées ne sont acceptées qu'en GIF.",
  banner_not_allowed: "L'image de bannière est réservée aux adhérents et aux admins.",
  image_too_large: "Image trop lourde.",
  image_invalid_type: "Format non pris en charge.",
};

export function imageUploadError(code: string | null): string {
  return (code !== null ? ERRORS[code] : undefined) ?? "Envoi impossible pour le moment, réessaie plus tard.";
}
