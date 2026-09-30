import { imageAccept, imageFormatsHint, imageUploadError } from "./custom-image-rules";

/**
 * Story 30.40. What the file pickers accept and what they say, from what the member may send, and a readable
 * reason for every refusal of the API.
 */
describe("custom image rules", () => {
  test("the picker offers GIF only when it is allowed", () => {
    expect(imageAccept(false)).toBe("image/jpeg,image/png,image/webp");
    expect(imageAccept(true)).toBe("image/jpeg,image/png,image/webp,image/gif");
  });

  test("the hint states formats and weight for each slot", () => {
    expect(imageFormatsHint("avatar", false)).toBe("JPEG, PNG ou WebP, 5 Mo max.");
    expect(imageFormatsHint("avatar", true)).toBe("JPEG, PNG ou WebP (5 Mo max), ou GIF animé (10 Mo max).");
    expect(imageFormatsHint("banner", false)).toBe("JPEG, PNG ou WebP, 10 Mo max.");
    expect(imageFormatsHint("banner", true)).toBe("JPEG, PNG, WebP ou GIF animé, 10 Mo max.");
  });

  test("every refusal reads as a sentence", () => {
    expect(imageUploadError("image_gif_admin_only")).toBe("Les GIF sont réservés aux admins.");
    expect(imageUploadError("image_animation_unsupported")).toBe("Les images animées ne sont acceptées qu'en GIF.");
    expect(imageUploadError("banner_not_allowed")).toBe("L'image de bannière est réservée aux adhérents et aux admins.");
    expect(imageUploadError("image_too_large")).toBe("Image trop lourde.");
    expect(imageUploadError("image_invalid_type")).toBe("Format non pris en charge.");
    expect(imageUploadError(null)).toBe("Envoi impossible pour le moment, réessaie plus tard.");
  });
});
