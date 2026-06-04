import type { PublicPostType } from "./content-types";

const postTypeLabels: Record<PublicPostType, string> = {
  news: "Actualité",
  recap: "Récap",
  announcement: "Annonce",
};

export function getPostTypeLabel(type: PublicPostType) {
  return postTypeLabels[type];
}
